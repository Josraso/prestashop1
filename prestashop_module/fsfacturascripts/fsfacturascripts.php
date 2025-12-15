<?php
/**
 * FacturaScripts Integration Module v3.0.0
 * Usa API REST de FacturaScripts directamente
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
        $this->version = '3.0.8';
        $this->author = 'FacturaScripts';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('FacturaScripts Integration v3');
        $this->description = $this->l('Integración con FacturaScripts usando API REST: webhooks + consulta de facturas.');
        $this->confirmUninstall = $this->l('¿Estás seguro de que quieres desinstalar este módulo?');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        // Crear tabla
        if (!$this->createTables()) {
            return false;
        }

        // Instalar tab
        if (!$this->installTab()) {
            return false;
        }

        // Registrar hooks
        return $this->registerHook('actionOrderStatusPostUpdate') &&
               $this->registerHook('actionValidateOrder') &&
               $this->registerHook('displayOrderDetail') &&
               $this->registerHook('displayCustomerAccount');
    }

    public function uninstall()
    {
        $this->uninstallTab();
        $this->dropTables();
        return parent::uninstall();
    }

    private function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminFsFacturas';
        $tab->name = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Facturas FacturaScripts';
        }
        $tab->id_parent = (int)Tab::getIdFromClassName('AdminParentOrders');
        $tab->module = $this->name;
        return $tab->add();
    }

    private function uninstallTab()
    {
        $id_tab = (int)Tab::getIdFromClassName('AdminFsFacturas');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return $tab->delete();
        }
        return true;
    }

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

        $result = Db::getInstance()->execute($sql);

        if (!$result) {
            PrestaShopLogger::addLog(
                'FacturaScripts: Error al crear tabla - ' . Db::getInstance()->getMsgError(),
                3,
                null,
                'Module',
                0,
                true
            );
        }

        return $result;
    }

    private function tableExists()
    {
        $sql = 'SHOW TABLES LIKE "' . _DB_PREFIX_ . 'fs_facturascripts"';
        return (bool)Db::getInstance()->executeS($sql);
    }

    private function dropTables()
    {
        $sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'fs_facturascripts`';
        return Db::getInstance()->execute($sql);
    }

    /**
     * Configuración del módulo
     */
    public function getContent()
    {
        $output = '';

        // Crear tabla manualmente
        if (Tools::isSubmit('submitCreateTable')) {
            if ($this->tableExists()) {
                $output .= $this->displayWarning($this->l('La tabla ya existe'));
            } else {
                if ($this->createTables()) {
                    $output .= $this->displayConfirmation($this->l('Tabla creada correctamente'));
                } else {
                    $output .= $this->displayError($this->l('Error al crear tabla. Revisa logs.'));
                }
            }
        }

        // Sincronizar pedidos históricos usando API
        if (Tools::isSubmit('submitSyncOrders')) {
            if (!$this->tableExists()) {
                $output .= $this->displayError($this->l('ERROR: Tabla no existe. Crea la tabla primero.'));
            } else {
                $result = $this->syncOrdersFromAPI();

                if (is_array($result) && isset($result['error'])) {
                    $output .= $this->displayError($this->l('Error: ') . $result['error']);
                } elseif (is_numeric($result)) {
                    if ($result == 0) {
                        $output .= $this->displayWarning($this->l('No se encontraron pedidos para sincronizar.'));
                    } else {
                        $output .= $this->displayConfirmation($this->l('✓ Sincronizados ') . $result . $this->l(' pedidos'));
                    }
                } else {
                    $output .= $this->displayError($this->l('Error inesperado'));
                }
            }
        }

        // Guardar configuración
        if (Tools::isSubmit('submitFsFacturaScriptsConfig')) {
            // Webhooks
            Configuration::updateValue('FS_WEBHOOK_ENABLED', (int)Tools::getValue('FS_WEBHOOK_ENABLED'));
            Configuration::updateValue('FS_WEBHOOK_URL', Tools::getValue('FS_WEBHOOK_URL'));
            Configuration::updateValue('FS_WEBHOOK_TOKEN', Tools::getValue('FS_WEBHOOK_TOKEN'));

            // API REST
            Configuration::updateValue('FS_API_ENABLED', (int)Tools::getValue('FS_API_ENABLED'));
            Configuration::updateValue('FS_API_URL', Tools::getValue('FS_API_URL'));
            Configuration::updateValue('FS_API_KEY', Tools::getValue('FS_API_KEY'));
            Configuration::updateValue('FS_PDF_FORMAT', (int)Tools::getValue('FS_PDF_FORMAT'));

            $output .= $this->displayConfirmation($this->l('Configuración guardada'));
        }

        return $output . $this->displayForm();
    }

    private function displayForm()
    {
        $tableStatus = $this->tableExists()
            ? '<span style="color: green;">✓ Tabla OK</span>'
            : '<span style="color: red;">✗ Tabla NO existe</span>';

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuración FacturaScripts v3.0.0'),
                    'icon' => 'icon-cogs'
                ],
                'description' => '<div class="alert alert-info">
                    <strong>Estado BD:</strong> ' . $tableStatus . '<br>
                    <a href="https://facturascripts.com/publicaciones/la-api-rest-de-facturascripts-912" target="_blank">📖 Documentación API</a>
                </div>',
                'input' => [
                    // SECCIÓN 1: WEBHOOKS (para importar pedidos nuevos)
                    [
                        'type' => 'html',
                        'name' => 'webhook_section',
                        'html_content' => '<hr><h3>🔔 WEBHOOKS (Importar pedidos nuevos a FacturaScripts)</h3>'
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Activar Webhooks'),
                        'name' => 'FS_WEBHOOK_ENABLED',
                        'desc' => $this->l('Enviar pedidos automáticamente a FacturaScripts cuando se crean/actualizan'),
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'webhook_on', 'value' => 1, 'label' => $this->l('Sí')],
                            ['id' => 'webhook_off', 'value' => 0, 'label' => $this->l('No')]
                        ]
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('URL Webhook FacturaScripts'),
                        'name' => 'FS_WEBHOOK_URL',
                        'desc' => $this->l('URL base de FacturaScripts (ej: https://mesascomedor.es)'),
                        'size' => 50
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Token Webhook'),
                        'name' => 'FS_WEBHOOK_TOKEN',
                        'desc' => $this->l('Token del plugin FacturaScripts (copiar de Configuración PrestaShop)'),
                        'size' => 50
                    ],

                    // SECCIÓN 2: API REST (para consultar facturas)
                    [
                        'type' => 'html',
                        'name' => 'api_section',
                        'html_content' => '<hr><h3>🔌 API REST (Consultar facturas desde FacturaScripts)</h3>'
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Activar API REST'),
                        'name' => 'FS_API_ENABLED',
                        'desc' => $this->l('Consultar facturas usando API REST de FacturaScripts'),
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'api_on', 'value' => 1, 'label' => $this->l('Sí')],
                            ['id' => 'api_off', 'value' => 0, 'label' => $this->l('No')]
                        ]
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('URL API FacturaScripts'),
                        'name' => 'FS_API_URL',
                        'desc' => $this->l('URL base de FacturaScripts (ej: https://mesascomedor.es)'),
                        'size' => 50
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API Key'),
                        'name' => 'FS_API_KEY',
                        'desc' => $this->l('Crear en FacturaScripts: Panel Control > Claves API > Nueva'),
                        'size' => 50
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Formato PDF'),
                        'name' => 'FS_PDF_FORMAT',
                        'desc' => $this->l('ID del formato de impresión (0 = formato por defecto)'),
                        'size' => 10
                    ]
                ],
                'submit' => [
                    'title' => $this->l('Guardar'),
                    'class' => 'btn btn-default pull-right'
                ],
                'buttons' => [
                    [
                        'type' => 'submit',
                        'title' => $this->l('Crear tabla'),
                        'icon' => 'process-icon-database',
                        'class' => 'btn btn-warning pull-right',
                        'name' => 'submitCreateTable'
                    ],
                    [
                        'type' => 'submit',
                        'title' => $this->l('Sincronizar pedidos históricos'),
                        'icon' => 'process-icon-refresh',
                        'class' => 'btn btn-info pull-right',
                        'name' => 'submitSyncOrders'
                    ]
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
                // Webhooks
                'FS_WEBHOOK_ENABLED' => Configuration::get('FS_WEBHOOK_ENABLED'),
                'FS_WEBHOOK_URL' => Configuration::get('FS_WEBHOOK_URL'),
                'FS_WEBHOOK_TOKEN' => Configuration::get('FS_WEBHOOK_TOKEN'),
                // API REST
                'FS_API_ENABLED' => Configuration::get('FS_API_ENABLED'),
                'FS_API_URL' => Configuration::get('FS_API_URL'),
                'FS_API_KEY' => Configuration::get('FS_API_KEY'),
                'FS_PDF_FORMAT' => Configuration::get('FS_PDF_FORMAT', 0)
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Sincronizar pedidos históricos usando API REST de FacturaScripts
     */
    private function syncOrdersFromAPI()
    {
        if (!Configuration::get('FS_API_ENABLED')) {
            return ['error' => 'API REST no está activada'];
        }

        $fs_url = Configuration::get('FS_API_URL');
        $api_key = Configuration::get('FS_API_KEY');

        if (empty($fs_url) || empty($api_key)) {
            return ['error' => 'URL API o API Key no configurados'];
        }

        // Llamar al endpoint de FACTURAS (no albaranes): /api/3/facturaclientes
        $api_url = rtrim($fs_url, '/') . '/api/3/facturaclientes';

        PrestaShopLogger::addLog(
            "FacturaScripts API: Obteniendo FACTURAS desde {$api_url}",
            1,
            null,
            'Module',
            0,
            true
        );

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Token:' . $api_key,
            'Accept:application/json'
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        PrestaShopLogger::addLog(
            "FacturaScripts API: HTTP {$http_code} - Primeros 500 chars: " . substr($response, 0, 500),
            1,
            null,
            'Module',
            0,
            true
        );

        if ($curl_error) {
            return ['error' => "Error de conexión: {$curl_error}"];
        }

        if ($http_code == 404) {
            return ['error' => "Error 404: Endpoint 'facturaclientes' no encontrado. URL: {$api_url}"];
        }

        if ($http_code == 401) {
            return ['error' => "Error 401: Token inválido o sin permisos"];
        }

        if ($http_code != 200) {
            return ['error' => "Error HTTP {$http_code}. Respuesta: " . substr($response, 0, 200)];
        }

        $facturas = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Error al decodificar JSON: ' . json_last_error_msg()];
        }

        if (!is_array($facturas)) {
            return ['error' => 'La respuesta no es un array. Tipo: ' . gettype($facturas)];
        }

        $sincronizados = 0;

        foreach ($facturas as $factura) {
            // Solo procesar facturas que tienen numero2 (referencia PrestaShop)
            if (empty($factura['numero2'])) {
                continue;
            }

            // Buscar pedido en PrestaShop por referencia
            $sql = 'SELECT id_order FROM ' . _DB_PREFIX_ . 'orders WHERE reference = "' . pSQL($factura['numero2']) . '"';
            $order_id = Db::getInstance()->getValue($sql);

            if (!$order_id) {
                continue;
            }

            // Verificar si ya existe
            $exists = Db::getInstance()->getValue(
                'SELECT id_fs_facturascripts FROM ' . _DB_PREFIX_ . 'fs_facturascripts WHERE id_order = ' . (int)$order_id
            );

            $data_insert = [
                'id_order' => (int)$order_id,
                'order_reference' => pSQL($factura['numero2']),
                'fs_albaran_id' => null, // No usamos albaranes, solo facturas
                'fs_factura_id' => (int)$factura['idfactura'],
                'fs_factura_code' => pSQL($factura['codigo']),
                'webhook_sent' => 1,
                'webhook_response' => 'Sincronizado desde API (facturas)',
                'date_upd' => date('Y-m-d H:i:s')
            ];

            if ($exists) {
                Db::getInstance()->update('fs_facturascripts', $data_insert, 'id_order = ' . (int)$order_id);
            } else {
                $data_insert['date_add'] = date('Y-m-d H:i:s');
                Db::getInstance()->insert('fs_facturascripts', $data_insert);
            }

            $sincronizados++;
        }

        PrestaShopLogger::addLog(
            "FacturaScripts API: ✓ Sincronizados {$sincronizados} pedidos de " . count($facturas) . " facturas encontradas",
            1,
            null,
            'Module',
            0,
            true
        );

        return $sincronizados;
    }

    /**
     * Hook: Webhook cuando se crea/actualiza pedido
     */
    public function hookActionValidateOrder($params)
    {
        if (!Configuration::get('FS_WEBHOOK_ENABLED')) {
            return;
        }

        $order = $params['order'];
        $this->sendWebhookToFacturaScripts($order);
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        if (!Configuration::get('FS_WEBHOOK_ENABLED')) {
            return;
        }

        $order = new Order($params['id_order']);
        $this->sendWebhookToFacturaScripts($order);
    }

    private function sendWebhookToFacturaScripts($order)
    {
        $fs_url = Configuration::get('FS_WEBHOOK_URL');
        $fs_token = Configuration::get('FS_WEBHOOK_TOKEN');

        if (empty($fs_url) || empty($fs_token)) {
            return;
        }

        $webhook_url = rtrim($fs_url, '/') . '/WebhookPrestashop?token=' . $fs_token;
        $payload = [
            'order_id' => $order->id,
            'order_reference' => $order->reference,
            'current_state' => $order->getCurrentState(),
            'total_paid' => $order->total_paid,
            'id_customer' => $order->id_customer
        ];

        try {
            $ch = curl_init($webhook_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->saveWebhookData($order, $response, $http_code == 200);
        } catch (Exception $e) {
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

        if ($success && isset($response_data['albaran_id'])) {
            $data['fs_albaran_id'] = (int)$response_data['albaran_id'];
        }
        if ($success && isset($response_data['factura_id'])) {
            $data['fs_factura_id'] = (int)$response_data['factura_id'];
            $data['fs_factura_code'] = pSQL($response_data['factura_code'] ?? '');
        }

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
     * Hook: Mostrar botón descarga en detalle pedido
     */
    public function hookDisplayOrderDetail($params)
    {
        $order = $params['order'];
        $fs_data = $this->getFacturaScriptsData($order->reference);

        if (!$fs_data || empty($fs_data['fs_factura_id'])) {
            return '';
        }

        $download_url = $this->getDownloadUrlAPI($fs_data['fs_factura_id']);

        $this->context->smarty->assign([
            'fs_factura_code' => $fs_data['fs_factura_code'],
            'fs_download_url' => $download_url
        ]);

        return $this->display(__FILE__, 'views/templates/hook/displayOrderDetail.tpl');
    }

    public function hookDisplayCustomerAccount($params)
    {
        return $this->display(__FILE__, 'views/templates/hook/displayCustomerAccount.tpl');
    }

    private function getFacturaScriptsData($order_reference)
    {
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'fs_facturascripts
                WHERE order_reference = "' . pSQL($order_reference) . '"';
        return Db::getInstance()->getRow($sql);
    }

    /**
     * URL de descarga usando API REST: /api/3/exportarFacturaCliente/{id}
     * NOTA: Esta URL requiere el Token como parámetro GET porque es un enlace directo
     */
    private function getDownloadUrlAPI($factura_id)
    {
        $fs_url = Configuration::get('FS_API_URL');
        $api_key = Configuration::get('FS_API_KEY');
        $pdf_format = Configuration::get('FS_PDF_FORMAT', 0);

        // Para descargas directas (enlaces), el Token debe ir en URL
        $url = rtrim($fs_url, '/') . '/api/3/exportarFacturaCliente/' . $factura_id . '?type=PDF&Token=' . urlencode($api_key);

        if ($pdf_format > 0) {
            $url .= '&format=' . $pdf_format;
        }

        return $url;
    }
}
