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
        $this->className = 'FsFacturaScripts';
        $this->lang = false;
        $this->deleted = false;
        $this->explicitSelect = true;

        parent::__construct();

        $this->fields_list = [
            'id_fs_facturascripts' => [
                'title' => 'ID',
                'align' => 'center',
                'class' => 'fixed-width-xs'
            ],
            'id_order' => [
                'title' => 'Order ID',
                'align' => 'center',
                'class' => 'fixed-width-sm'
            ],
            'order_reference' => [
                'title' => 'Order Reference',
                'align' => 'left'
            ],
            'fs_factura_code' => [
                'title' => 'Factura Code',
                'align' => 'left'
            ],
            'fs_factura_id' => [
                'title' => 'Factura ID',
                'align' => 'center',
                'class' => 'fixed-width-sm'
            ],
            'webhook_sent' => [
                'title' => 'Webhook Sent',
                'align' => 'center',
                'active' => 'webhook_sent',
                'type' => 'bool',
                'class' => 'fixed-width-sm'
            ],
            'date_add' => [
                'title' => 'Created',
                'type' => 'datetime',
                'align' => 'right'
            ],
            'date_upd' => [
                'title' => 'Updated',
                'type' => 'datetime',
                'align' => 'right'
            ]
        ];

        $this->_select = '
            a.id_order,
            a.order_reference,
            a.fs_factura_code,
            a.fs_factura_id,
            a.webhook_sent,
            a.date_add,
            a.date_upd
        ';

        $this->_where = '';
        $this->_orderBy = 'date_upd';
        $this->_orderWay = 'DESC';

        $this->bulk_actions = [
            'delete' => [
                'text' => $this->l('Delete selected'),
                'icon' => 'icon-trash',
                'confirm' => $this->l('Delete selected items?')
            ]
        ];
    }

    public function renderList()
    {
        $this->addRowAction('view');
        $this->addRowAction('delete');

        $this->context->smarty->assign([
            'module_dir' => _MODULE_DIR_ . 'fsfacturascripts/'
        ]);

        $helper = '<div class="alert alert-info">
            <h4>ℹ️ FacturaScripts Integration</h4>
            <p><strong>Total registros:</strong> ' . $this->getRecordsCount() . '</p>
            <p>Esta tabla muestra las facturas sincronizadas entre PrestaShop y FacturaScripts.</p>
            <p>Para sincronizar pedidos históricos, ve a: <a href="' . $this->context->link->getAdminLink('AdminModules') . '&configure=fsfacturascripts">Configuración del módulo</a></p>
        </div>';

        return $helper . parent::renderList();
    }

    private function getRecordsCount()
    {
        $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'fs_facturascripts';
        return (int)Db::getInstance()->getValue($sql);
    }

    public function renderView()
    {
        if (!($id = $this->getIdValue())) {
            return $this->displayError($this->l('Invalid ID'));
        }

        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'fs_facturascripts WHERE id_fs_facturascripts = ' . (int)$id;
        $row = Db::getInstance()->getRow($sql);

        if (!$row) {
            return $this->displayError($this->l('Record not found'));
        }

        // Obtener info del pedido
        $order = new Order($row['id_order']);

        $this->context->smarty->assign([
            'fs_data' => $row,
            'order' => $order,
            'back_url' => $this->context->link->getAdminLink('AdminFsFacturas')
        ]);

        $this->tpl_view_vars = [
            'fs_data' => $row,
            'order' => $order
        ];

        return parent::renderView();
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addCSS(_MODULE_DIR_ . 'fsfacturascripts/views/css/admin.css');
    }

    protected function getIdValue()
    {
        return (int)Tools::getValue('id_fs_facturascripts');
    }
}
