<?php

namespace FacturaScripts\Plugins\Prestashop\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Prestashop\Lib\PrestashopConnection;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopConfig;
use FacturaScripts\Dinamic\Model\AlbaranCliente;

/**
 * Controlador para listar pedidos de PrestaShop y ver estado de importación
 */
class ListPedidosPrestashop extends Controller
{
    /** @var array */
    public $pedidos = [];

    /** @var int */
    public $totalPedidos = 0;

    /** @var int */
    public $importados = 0;

    /** @var int */
    public $pendientes = 0;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'Pedidos PrestaShop';
        $data['icon'] = 'fas fa-shopping-cart';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        $this->loadPedidos();

        // Procesar acciones
        $action = $this->request->request->get('action', '');
        if ($action === 'import-order' && $permissions->allowUpdate) {
            $this->importOrderAction();
        }
    }

    /**
     * Carga los pedidos de PrestaShop y verifica cuáles están importados
     */
    private function loadPedidos(): void
    {
        $config = PrestashopConfig::getActive();

        if (!$config || !$config->shop_url || !$config->api_key) {
            Tools::log()->warning('PrestaShop no está configurado');
            return;
        }

        try {
            $connection = new PrestashopConnection($config);

            // Obtener últimos 100 pedidos
            $ordersXml = $connection->getOrders(100);

            if (!$ordersXml) {
                Tools::log()->error('No se pudieron obtener pedidos de PrestaShop');
                return;
            }

            $albaranModel = new AlbaranCliente();

            foreach ($ordersXml as $orderXml) {
                $orderId = (int)$orderXml->id;
                $orderRef = (string)$orderXml->reference;
                $customerId = (int)$orderXml->id_customer;
                $currentState = (int)$orderXml->current_state;
                $totalPaid = (float)$orderXml->total_paid;
                $dateAdd = (string)$orderXml->date_add;

                // Verificar si está importado (buscar albarán con numero2 = order_reference)
                $where = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('numero2', $orderRef)];
                $albaran = $albaranModel->all($where, [], 0, 1);
                $importado = !empty($albaran);

                if ($importado) {
                    $this->importados++;
                } else {
                    $this->pendientes++;
                }

                // Obtener nombre del cliente
                $customerName = $this->getCustomerName($connection, $customerId);

                $this->pedidos[] = [
                    'id' => $orderId,
                    'reference' => $orderRef,
                    'customer_id' => $customerId,
                    'customer_name' => $customerName,
                    'state' => $currentState,
                    'total' => $totalPaid,
                    'date' => $dateAdd,
                    'importado' => $importado,
                    'idalbaran' => $importado && !empty($albaran) ? $albaran[0]->idalbaran : null
                ];
            }

            $this->totalPedidos = count($this->pedidos);

        } catch (\Exception $e) {
            Tools::log()->error('Error cargando pedidos: ' . $e->getMessage());
        }
    }

    /**
     * Obtiene el nombre del cliente desde PrestaShop
     */
    private function getCustomerName(PrestashopConnection $connection, int $customerId): string
    {
        try {
            $customerXml = $connection->getCustomer($customerId);
            if ($customerXml) {
                $firstname = (string)$customerXml->firstname;
                $lastname = (string)$customerXml->lastname;
                return trim($firstname . ' ' . $lastname);
            }
        } catch (\Exception $e) {
            // Ignorar errores
        }

        return 'Cliente #' . $customerId;
    }

    /**
     * Importa un pedido específico
     */
    private function importOrderAction(): void
    {
        $orderId = (int)$this->request->request->get('order_id', 0);

        if ($orderId <= 0) {
            Tools::log()->error('ID de pedido inválido');
            return;
        }

        $config = PrestashopConfig::getActive();

        if (!$config) {
            Tools::log()->error('Configuración no encontrada');
            return;
        }

        try {
            $connection = new PrestashopConnection($config);
            $orderXml = $connection->getOrder($orderId);

            if (!$orderXml) {
                Tools::log()->error("Pedido {$orderId} no encontrado en PrestaShop");
                return;
            }

            // Importar usando OrdersDownload
            $importer = new \FacturaScripts\Plugins\Prestashop\Lib\Actions\OrdersDownload();
            $reflection = new \ReflectionClass($importer);
            $method = $reflection->getMethod('importOrder');
            $method->setAccessible(true);

            $result = $method->invoke($importer, $orderXml);

            if ($result && is_array($result)) {
                Tools::log()->info("✓ Pedido {$orderId} importado correctamente");
            } else {
                Tools::log()->warning("Pedido {$orderId} ya estaba importado");
            }

            // Recargar pedidos
            $this->pedidos = [];
            $this->importados = 0;
            $this->pendientes = 0;
            $this->loadPedidos();

        } catch (\Exception $e) {
            Tools::log()->error("Error importando pedido {$orderId}: " . $e->getMessage());
        }
    }
}
