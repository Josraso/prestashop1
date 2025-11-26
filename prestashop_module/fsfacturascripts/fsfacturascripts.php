<?php
/**
 * FacturaScripts Integration Module
 *
 * @author    FacturaScripts Team
 * @copyright Copyright (c) 2025 FacturaScripts
 * @license   MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FsFacturaScripts extends Module
{
    public function __construct()
    {
        $this->name = 'fsfacturascripts';
        $this->tab = 'billing_invoicing';
        $this->version = '1.0.0';
        $this->author = 'FacturaScripts';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('FacturaScripts Integration');
        $this->description = $this->l('Integración con FacturaScripts: webhooks en tiempo real y descarga de facturas.');
        $this->confirmUninstall = $this->l('¿Estás seguro de que quieres desinstalar este módulo?');
    }

    /**
     * Instalación del módulo
     */
    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        // Crear tabla para guardar relación pedido → factura
        if (!$this->createTables()) {
            return false;
        }

        // Registrar hooks (SOLO displayAdminOrder para evitar duplicados)
        return $this->registerHook('actionOrderStatusPostUpdate') &&
               $this->registerHook('actionValidateOrder') &&
               $this->registerHook('displayAdminOrder') &&
               $this->registerHook('displayAdminOrdersListAfter') &&
               $this->registerHook('displayOrderDetail') &&
               $this->registerHook('displayCustomerAccount');
    }

    /**
     * Desinstalación del módulo
     */
    public function uninstall()
    {
        // Eliminar tabla
        $this->dropTables();

        return parent::uninstall();
    }

    /**
     * Crear tablas en la base de datos
     */
    private function createTables()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'fs_facturascripts` (
            `id_fs_facturascripts` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT(11) UNSIGNED NOT NULL,
            `order_reference` VARCHAR(64) NOT NULL,
            `fs_albaran_id` INT(11) DEFAULT NULL,
            `fs_factura_id` INT(11) DEFAULT NULL,
            `fs_factura_code` VARCHAR(64) DEFAULT NULL,
            `webhook_sent` TINYINT(1) DEFAULT 0,
            `webhook_response` TEXT DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_fs_facturascripts`),
            UNIQUE KEY `id_order` (`id_order`),
            KEY `order_reference` (`order_reference`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Eliminar tablas de la base de datos
     */
    private function dropTables()
    {
        $sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'fs_facturascripts`';
        return Db::getInstance()->execute($sql);
    }

    /**
     * Página de configuración del módulo
     */
    public function getContent()
    {
        $output = '';

        // Procesar formulario
        if (Tools::isSubmit('submitFsFacturaScriptsConfig')) {
            Configuration::updateValue('FS_FACTURASCRIPTS_URL', Tools::getValue('FS_FACTURASCRIPTS_URL'));
            Configuration::updateValue('FS_FACTURASCRIPTS_TOKEN', Tools::getValue('FS_FACTURASCRIPTS_TOKEN'));
            Configuration::updateValue('FS_WEBHOOK_ENABLED', (int)Tools::getValue('FS_WEBHOOK_ENABLED'));

            $output .= $this->displayConfirmation($this->l('Configuración guardada correctamente'));
        }

        return $output . $this->displayForm();
    }

    /**
     * Formulario de configuración
     */
    public function displayForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuración FacturaScripts'),
                    'icon' => 'icon-cogs'
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('URL de FacturaScripts'),
                        'name' => 'FS_FACTURASCRIPTS_URL',
                        'desc' => $this->l('URL completa de tu instalación de FacturaScripts (ej: https://tudominio.com)'),
                        'required' => true,
                        'size' => 50
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Token Webhook'),
                        'name' => 'FS_FACTURASCRIPTS_TOKEN',
                        'desc' => $this->l('Token de seguridad generado en FacturaScripts > Configuración PrestaShop > Webhooks'),
                        'required' => true,
                        'size' => 50
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Activar Webhooks'),
                        'name' => 'FS_WEBHOOK_ENABLED',
                        'desc' => $this->l('Enviar webhooks automáticos a FacturaScripts cuando se crea/actualiza un pedido'),
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Sí')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')]
                        ]
                    ]
                ],
                'submit' => [
                    'title' => $this->l('Guardar'),
                    'class' => 'btn btn-default pull-right'
                ]
            ]
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitFsFacturaScriptsConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => [
                'FS_FACTURASCRIPTS_URL' => Configuration::get('FS_FACTURASCRIPTS_URL'),
                'FS_FACTURASCRIPTS_TOKEN' => Configuration::get('FS_FACTURASCRIPTS_TOKEN'),
                'FS_WEBHOOK_ENABLED' => Configuration::get('FS_WEBHOOK_ENABLED')
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Hook: Cuando se crea un nuevo pedido
     */
    public function hookActionValidateOrder($params)
    {
        if (!Configuration::get('FS_WEBHOOK_ENABLED')) {
            return;
        }

        $order = $params['order'];
        $this->sendWebhookToFacturaScripts($order);
    }

    /**
     * Hook: Cuando se actualiza el estado de un pedido
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        if (!Configuration::get('FS_WEBHOOK_ENABLED')) {
            return;
        }

        $order = new Order($params['id_order']);
        $this->sendWebhookToFacturaScripts($order);
    }

    /**
     * Enviar webhook a FacturaScripts
     */
    private function sendWebhookToFacturaScripts($order)
    {
        $fs_url = Configuration::get('FS_FACTURASCRIPTS_URL');
        $fs_token = Configuration::get('FS_FACTURASCRIPTS_TOKEN');

        if (empty($fs_url) || empty($fs_token)) {
            return;
        }

        // Preparar datos del webhook
        $webhook_url = rtrim($fs_url, '/') . '/WebhookPrestashop?token=' . $fs_token;
        $payload = [
            'order_id' => $order->id,
            'order_reference' => $order->reference,
            'current_state' => $order->getCurrentState(),
            'total_paid' => $order->total_paid,
            'id_customer' => $order->id_customer
        ];

        // Enviar webhook con cURL
        try {
            $ch = curl_init($webhook_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Guardar respuesta
            $this->saveWebhookData($order, $response, $http_code == 200);

        } catch (Exception $e) {
            // Log error pero no bloquear el flujo
            PrestaShopLogger::addLog(
                'FacturaScripts Webhook Error: ' . $e->getMessage(),
                3,
                null,
                'Order',
                $order->id,
                true
            );
        }
    }

    /**
     * Guardar datos del webhook en BD
     */
    private function saveWebhookData($order, $response, $success)
    {
        $response_data = json_decode($response, true);

        $data = [
            'id_order' => (int)$order->id,
            'order_reference' => pSQL($order->reference),
            'webhook_sent' => 1,
            'webhook_response' => pSQL($response),
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s')
        ];

        // Si hay datos de albarán/factura en la respuesta, guardarlos
        if ($success && isset($response_data['albaran_id'])) {
            $data['fs_albaran_id'] = (int)$response_data['albaran_id'];
        }
        if ($success && isset($response_data['factura_id'])) {
            $data['fs_factura_id'] = (int)$response_data['factura_id'];
            $data['fs_factura_code'] = pSQL($response_data['factura_code'] ?? '');
        }

        // Insertar o actualizar
        $existing = Db::getInstance()->getValue(
            'SELECT id_fs_facturascripts FROM ' . _DB_PREFIX_ . 'fs_facturascripts WHERE id_order = ' . (int)$order->id
        );

        if ($existing) {
            unset($data['date_add']);
            Db::getInstance()->update('fs_facturascripts', $data, 'id_order = ' . (int)$order->id);
        } else {
            Db::getInstance()->insert('fs_facturascripts', $data);
        }
    }

    /**
     * Hook: displayAdminOrder - No mostrar nada
     */
    public function hookDisplayAdminOrder($params)
    {
        // No mostrar nada en la ficha del pedido
        return '';
    }

    /**
     * Hook: Mostrar botón en detalle del pedido (front - cuenta cliente)
     */
    public function hookDisplayOrderDetail($params)
    {
        $order = $params['order'];
        $fs_data = $this->getFacturaScriptsData($order->reference);

        if (!$fs_data || empty($fs_data['fs_factura_id'])) {
            return '';
        }

        $download_url = $this->getDownloadUrl($order->reference);

        $this->context->smarty->assign([
            'fs_factura_code' => $fs_data['fs_factura_code'],
            'fs_download_url' => $download_url
        ]);

        return $this->display(__FILE__, 'views/templates/hook/displayOrderDetail.tpl');
    }

    /**
     * Hook: Mostrar enlace en cuenta del cliente (listado de pedidos)
     */
    public function hookDisplayCustomerAccount($params)
    {
        return ''; // Placeholder para futuras mejoras
    }

    /**
     * Obtener datos de FacturaScripts por order_reference
     */
    private function getFacturaScriptsData($order_reference)
    {
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'fs_facturascripts
                WHERE order_reference = "' . pSQL($order_reference) . '"';

        return Db::getInstance()->getRow($sql);
    }

    /**
     * Construir URL de descarga
     */
    private function getDownloadUrl($order_reference)
    {
        $fs_url = Configuration::get('FS_FACTURASCRIPTS_URL');
        $fs_token = Configuration::get('FS_FACTURASCRIPTS_TOKEN');

        return rtrim($fs_url, '/') . '/DownloadInvoicePrestashop?token=' . $fs_token . '&order_ref=' . urlencode($order_reference);
    }
}
