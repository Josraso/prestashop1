<?php
/**
 * Controlador admin para descargar PDF de factura
 * Usa Token en header para autenticación correcta
 */

class FsFacturaScriptsDownloadAdminModuleFrontController extends ModuleFrontController
{
    public $auth = false; // No requiere autenticación de cliente (es para admin)
    public $ssl = true;

    public function initContent()
    {
        // Verificar que sea admin
        if (!isset($this->context->employee) || !$this->context->employee->id) {
            die('Acceso denegado');
        }

        $factura_id = (int)Tools::getValue('id');

        if (!$factura_id) {
            die('ID de factura no válido');
        }

        // Verificar que existe
        $sql = 'SELECT fs_factura_id, fs_factura_code FROM ' . _DB_PREFIX_ . 'fs_facturascripts
                WHERE fs_factura_id = ' . (int)$factura_id;
        $factura = Db::getInstance()->getRow($sql);

        if (!$factura) {
            die('Factura no encontrada');
        }

        // Obtener PDF usando API con Token en HEADER
        $fs_url = Configuration::get('FS_API_URL');
        $api_key = Configuration::get('FS_API_KEY');
        $pdf_format = Configuration::get('FS_PDF_FORMAT', 0);

        $api_url = rtrim($fs_url, '/') . '/api/3/exportarFacturaCliente/' . $factura_id;

        // Añadir parámetros
        $params = ['type' => 'PDF'];
        if ($pdf_format > 0) {
            $params['format'] = $pdf_format;
        }
        $api_url .= '?' . http_build_query($params);

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Token:' . $api_key,
            'Accept:application/pdf'
        ]);

        $pdf_content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code != 200) {
            PrestaShopLogger::addLog(
                "Error al descargar PDF factura {$factura_id}: HTTP {$http_code}",
                3,
                null,
                'Module',
                0,
                true
            );
            die('Error al descargar factura: HTTP ' . $http_code);
        }

        // Usar el código de factura como nombre del archivo
        $filename = !empty($factura['fs_factura_code'])
            ? $factura['fs_factura_code'] . '.pdf'
            : 'factura_' . $factura_id . '.pdf';

        // Servir el PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf_content));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        echo $pdf_content;
        exit;
    }
}
