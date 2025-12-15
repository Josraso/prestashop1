<?php
/**
 * Admin Controller: Gestión de facturas FacturaScripts
 */

class AdminFsFacturasController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'fs_facturascripts';
        $this->identifier = 'id_fs_facturascripts';
        $this->className = 'stdClass'; // No usamos ObjectModel
        $this->lang = false;
        $this->deleted = false;
        $this->explicitSelect = true;
        $this->allow_export = true;

        $this->context = Context::getContext();

        $this->fields_list = [
            'id_fs_facturascripts' => [
                'title' => 'ID',
                'align' => 'center',
                'class' => 'fixed-width-xs'
            ],
            'id_order' => [
                'title' => 'Pedido',
                'align' => 'center',
                'class' => 'fixed-width-sm'
            ],
            'order_reference' => [
                'title' => 'Ref. Pedido',
                'align' => 'left'
            ],
            'fs_factura_code' => [
                'title' => 'Código Factura',
                'align' => 'left'
            ],
            'fs_factura_id' => [
                'title' => 'ID Factura',
                'align' => 'center',
                'class' => 'fixed-width-sm'
            ],
            'date_add' => [
                'title' => 'Creado',
                'type' => 'datetime',
                'align' => 'right'
            ],
            'date_upd' => [
                'title' => 'Actualizado',
                'type' => 'datetime',
                'align' => 'right'
            ]
        ];

        parent::__construct();

        $this->_select = 'a.*';
        $this->_where = '';
        $this->_orderBy = 'a.id_fs_facturascripts';
        $this->_orderWay = 'DESC';

        // No permitir acciones de eliminar
        $this->bulk_actions = [];
    }

    public function renderList()
    {
        // Añadir botón de descarga personalizado
        $this->addRowActionSkipList('download', []);

        // NO añadir acciones que hagan clicable la fila

        $total = $this->getRecordsCount();

        $helper = '<div class="panel">
            <div class="panel-heading">
                <i class="icon-info"></i> Información de sincronización
            </div>
            <div class="panel-body">
                <p><strong>Total de registros sincronizados:</strong> ' . $total . '</p>
                <p>Esta tabla muestra las facturas sincronizadas entre PrestaShop y FacturaScripts.</p>
                <p><a href="' . $this->context->link->getAdminLink('AdminModules') . '&configure=fsfacturascripts" class="btn btn-primary">
                    <i class="icon-cog"></i> Ir a Configuración
                </a></p>
            </div>
        </div>';

        return $helper . parent::renderList();
    }

    private function getRecordsCount()
    {
        $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fs_facturascripts';
        return (int)Db::getInstance()->getValue($sql);
    }

    public function displayDownloadLink($token, $id)
    {
        // Obtener datos de la factura
        $sql = 'SELECT fs_factura_id, fs_factura_code FROM ' . _DB_PREFIX_ . 'fs_facturascripts WHERE id_fs_facturascripts = ' . (int)$id;
        $row = Db::getInstance()->getRow($sql);

        if (!$row || empty($row['fs_factura_id'])) {
            return '<span class="text-muted">Sin factura</span>';
        }

        // URL de descarga usando el endpoint del API
        $fs_url = Configuration::get('FS_API_URL');
        $api_key = Configuration::get('FS_API_KEY');
        $pdf_format = Configuration::get('FS_PDF_FORMAT', 0);

        $download_url = rtrim($fs_url, '/') . '/api/3/exportarFacturaCliente/' . $row['fs_factura_id'] . '?type=PDF&Token=' . urlencode($api_key);

        if ($pdf_format > 0) {
            $download_url .= '&format=' . $pdf_format;
        }

        return '<a href="' . htmlspecialchars($download_url) . '" target="_blank" class="btn btn-default btn-sm">
            <i class="icon-download"></i> Descargar ' . htmlspecialchars($row['fs_factura_code']) . '
        </a>';
    }
}
