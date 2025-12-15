<?php
/**
 * Frontend Controller: Lista de facturas del cliente
 */

class FsFacturaScriptsMyInvoicesModuleFrontController extends ModuleFrontController
{
    public $auth = true;
    public $authRedirect = 'my-account';
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $customer = $this->context->customer;

        // Obtener pedidos del cliente
        $orders = Order::getCustomerOrders($customer->id);
        $invoices_data = [];

        foreach ($orders as $order) {
            // Buscar factura asociada
            $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'fs_facturascripts
                    WHERE id_order = ' . (int)$order['id_order'];
            $fs_data = Db::getInstance()->getRow($sql);

            if ($fs_data && !empty($fs_data['fs_factura_id'])) {
                $invoices_data[] = [
                    'order_reference' => $order['reference'],
                    'date_add' => $order['date_add'],
                    'total_paid' => $order['total_paid'],
                    'fs_factura_code' => $fs_data['fs_factura_code'],
                    'fs_factura_id' => $fs_data['fs_factura_id'],
                    'download_url' => $this->getDownloadUrl($fs_data['fs_factura_id'])
                ];
            }
        }

        $this->context->smarty->assign([
            'invoices' => $invoices_data,
            'page_title' => $this->l('Mis Facturas FacturaScripts')
        ]);

        $this->setTemplate('module:fsfacturascripts/views/templates/front/myinvoices.tpl');
    }

    private function getDownloadUrl($factura_id)
    {
        return $this->context->link->getModuleLink(
            'fsfacturascripts',
            'downloadpdf',
            ['id' => $factura_id],
            true
        );
    }

    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = [
            'title' => $this->l('My account'),
            'url' => $this->context->link->getPageLink('my-account')
        ];
        $breadcrumb['links'][] = [
            'title' => $this->l('My FacturaScripts Invoices'),
            'url' => $this->context->link->getModuleLink('fsfacturascripts', 'myinvoices')
        ];
        return $breadcrumb;
    }
}
