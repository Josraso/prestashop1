<?php

namespace FacturaScripts\Plugins\Prestashop\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopConfig;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controlador público para descargar PDFs de facturas desde PrestaShop
 */
class DownloadInvoicePrestashop extends Controller
{
    public function publicCore(&$response): void
    {
        parent::publicCore($response);

        // Si es GET, mostrar mensaje informativo
        if ($this->request->getMethod() === 'GET') {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Descarga Facturas - FacturaScripts</title>
    <style>
        body { font-family: Arial, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
               min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
        .container { background: white; border-radius: 16px; padding: 40px; max-width: 500px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        h1 { color: #333; margin: 0 0 10px 0; font-size: 24px; }
        p { color: #666; line-height: 1.6; }
        .info { background: #eff6ff; border-left: 4px solid #3b82f6; padding: 15px; border-radius: 4px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📄 Descarga de Facturas</h1>
        <p>Este es un endpoint público para descargar facturas PDF desde PrestaShop.</p>
        <div class="info">
            <strong>ℹ️ Uso:</strong><br>
            <code>?token=XXX&order_ref=ABC123</code><br>
            o<br>
            <code>?token=XXX&invoice_id=456</code>
        </div>
    </div>
</body>
</html>';
            die();
        }

        // Validar token
        $token = $this->request->query->get('token', '');
        $config = PrestashopConfig::getActive();

        if (empty($token) || empty($config) || $token !== $config->webhook_token) {
            $this->sendError(403, 'Token inválido o no autorizado');
            return;
        }

        // Obtener parámetros
        $orderRef = $this->request->query->get('order_ref', '');
        $invoiceId = $this->request->query->get('invoice_id', '');

        // Opción 1: Buscar por referencia de pedido PrestaShop
        if (!empty($orderRef)) {
            $this->downloadByOrderReference($orderRef);
            return;
        }

        // Opción 2: Buscar por ID de factura directo
        if (!empty($invoiceId)) {
            $this->downloadByInvoiceId((int)$invoiceId);
            return;
        }

        $this->sendError(400, 'Debes proporcionar order_ref o invoice_id');
    }

    /**
     * Busca y descarga factura por referencia de pedido PrestaShop
     */
    private function downloadByOrderReference(string $orderRef): void
    {
        // Buscar albarán que tenga esta referencia en numero2
        $albaranModel = new AlbaranCliente();
        $where = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('numero2', $orderRef)];
        $albaranes = $albaranModel->all($where, [], 0, 1);

        if (empty($albaranes)) {
            $this->sendError(404, "No se encontró albarán con referencia de pedido: {$orderRef}");
            return;
        }

        $albaran = $albaranes[0];

        // Buscar factura asociada al albarán
        if (empty($albaran->idfactura)) {
            $this->sendError(404, "El albarán {$albaran->codigo} no tiene factura asociada todavía");
            return;
        }

        $this->downloadByInvoiceId($albaran->idfactura);
    }

    /**
     * Descarga factura por ID
     */
    private function downloadByInvoiceId(int $invoiceId): void
    {
        $facturaModel = new FacturaCliente();
        if (!$facturaModel->loadFromCode($invoiceId)) {
            $this->sendError(404, "No se encontró factura con ID: {$invoiceId}");
            return;
        }

        // Generar PDF usando el sistema de FacturaScripts
        try {
            $pdfExport = new \FacturaScripts\Core\ExportManager\PDFExport();
            $pdfExport->setOption('idempresa', $facturaModel->idempresa);

            // Generar el PDF
            $pdfExport->newDoc('FacturaCliente', $facturaModel->codigo, []);

            // Obtener el contenido del PDF
            $pdfContent = $pdfExport->getDoc();

            // Enviar headers
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="factura_' . $facturaModel->codigo . '.pdf"');
            header('Content-Length: ' . strlen($pdfContent));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            // Enviar PDF
            echo $pdfContent;
            exit;

        } catch (\Exception $e) {
            Tools::log()->error('Error generando PDF: ' . $e->getMessage());
            $this->sendError(500, 'Error al generar el PDF: ' . $e->getMessage());
        }
    }

    /**
     * Envía respuesta de error en JSON
     */
    private function sendError(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => $code
        ]);
        exit;
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['title'] = 'Download Invoice PrestaShop';
        $data['menu'] = 'admin';
        $data['showonmenu'] = false;
        return $data;
    }
}
