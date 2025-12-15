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
        $this->_orderBy = 'id_fs_facturascripts';
        $this->_orderWay = 'DESC';

        $this->bulk_actions = [
            'delete' => [
                'text' => $this->l('Eliminar seleccionados'),
                'icon' => 'icon-trash',
                'confirm' => $this->l('¿Eliminar elementos seleccionados?')
            ]
        ];
    }

    public function renderList()
    {
        // Eliminar acciones que causan problemas
        $this->addRowAction('delete');

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

    public function processBulkDelete()
    {
        if (!is_array($this->boxes) || !count($this->boxes)) {
            $this->errors[] = Tools::displayError('You must select at least one element to delete.');
            return false;
        }

        foreach ($this->boxes as $id) {
            $sql = 'DELETE FROM ' . _DB_PREFIX_ . 'fs_facturascripts WHERE id_fs_facturascripts = ' . (int)$id;
            Db::getInstance()->execute($sql);
        }

        $this->confirmations[] = $this->l('The selection has been successfully deleted.');
        return true;
    }

    public function processDelete()
    {
        $id = (int)Tools::getValue('id_fs_facturascripts');

        if (!$id) {
            $this->errors[] = Tools::displayError('An error occurred while deleting the object.');
            return false;
        }

        $sql = 'DELETE FROM ' . _DB_PREFIX_ . 'fs_facturascripts WHERE id_fs_facturascripts = ' . (int)$id;

        if (Db::getInstance()->execute($sql)) {
            Tools::redirectAdmin(self::$currentIndex . '&conf=1&token=' . $this->token);
        } else {
            $this->errors[] = Tools::displayError('An error occurred while deleting the object.');
        }

        return false;
    }
}
