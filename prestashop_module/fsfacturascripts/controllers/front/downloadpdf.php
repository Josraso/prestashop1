<?php
/**
 * Controlador para descargar PDF de factura
 * Este controlador actúa como proxy para evitar problemas con el Token en URL
 */

class FsFacturaScriptsDownloadpdfModuleFrontController extends ModuleFrontController
{
    public $auth = true;
    public $authRedirect = 'my-account';
    public $ssl = true;

    public function initContent()
    {
        $factura_id = (int)Tools::getValue('id');

        if (!$factura_id) {
            die('ID de factura no válido');
        }

        // Verificar que el cliente tenga acceso a esta factura
        $customer_id = $this->context->customer->id;

        $sql = 'SELECT f.fs_factura_id, o.id_customer
                FROM ' . _DB_PREFIX_ . 'fs_facturascripts f
                INNER JOIN ' . _DB_PREFIX_ . 'orders o ON f.id_order = o.id_order
                WHERE f.fs_factura_id = ' . (int)$factura_id . '
                AND o.id_customer = ' . (int)$customer_id;

        $factura = Db::getInstance()->getRow($sql);

        if (!$factura) {
            die('No tienes permiso para acceder a esta factura');
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

        // Servir el PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="factura_' . $factura_id . '.pdf"');
        header('Content-Length: ' . strlen($pdf_content));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        echo $pdf_content;
        exit;
    }
}
