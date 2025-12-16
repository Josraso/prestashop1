<?php

namespace FacturaScripts\Plugins\Prestashop\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopConfig;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopProductsTemp;
use FacturaScripts\Plugins\Prestashop\Lib\Actions\ProductsDownload;

/**
 * Controlador para gestionar productos de PrestaShop
 */
class ProductsPrestashop extends Controller
{
    /** @var array */
    public $products = [];

    /** @var PrestashopConfig */
    public $config;

    /** @var bool */
    public $productsLoaded = false;

    /** @var int */
    public $totalProducts = 0;

    /** @var int */
    public $importedCount = 0;

    /** @var int */
    public $errorCount = 0;

    /** @var string */
    public $filterStatus = 'todos';

    /** @var array */
    public $stats = [
        'importados' => 0,
        'ya_existen' => 0,
        'nuevos' => 0
    ];

    /** @var int */
    public $visibleProducts = 0;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'Productos PrestaShop';
        $data['icon'] = 'fas fa-box-open';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        // Asegurar que hay sesión iniciada
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Cargar configuración
        $this->config = PrestashopConfig::getActive();

        // Obtener filtro del request (GET para persistencia)
        $this->filterStatus = $this->request->query->get('filter', 'todos');

        // Procesar acciones
        $action = $this->request->request->get('action', '');
        switch ($action) {
            case 'download-products-batch':
                $this->downloadProductsBatchAction();
                return; // No renderizar vista, solo devolver JSON

            case 'get-total-products':
                $this->getTotalProductsAction();
                return; // No renderizar vista, solo devolver JSON

            case 'show-products':
                $this->showProductsAction();
                break;

            case 'clear-products':
                $this->clearProductsAction();
                break;

            case 'import-selected':
                $this->importSelectedAction();
                break;

            case 'update-ventasinstock':
                $this->updateVentaSinStockAction();
                break;
        }

        // Limpiar productos antiguos (más de 24h)
        PrestashopProductsTemp::cleanOld();

        // Obtener productos de BD temporal
        $sessionId = session_id();
        Tools::log()->info("===== DEBUG PRODUCTOS TEMP =====");
        Tools::log()->info("Session ID actual: {$sessionId}");

        $tempProducts = PrestashopProductsTemp::getProducts();

        Tools::log()->info("Productos en BD temporal: " . (empty($tempProducts) ? 'NO' : 'SI - ' . count($tempProducts)));

        if (!empty($tempProducts)) {
            Tools::log()->info("Primer producto: " . json_encode(array_slice($tempProducts, 0, 1)));
            $this->products = $tempProducts;
            $this->productsLoaded = true;
            $this->totalProducts = count($tempProducts);
            Tools::log()->info("✓ Cargados " . $this->totalProducts . " productos de BD temporal para mostrar");
        } else {
            Tools::log()->warning("✗ No hay productos en BD temporal");
            Tools::log()->warning("Verificando si hay productos de otras sesiones...");

            // Intentar recuperar productos de cualquier sesión reciente (últimas 24h)
            $db = new \FacturaScripts\Core\Base\DataBase();
            $sql = "SELECT * FROM " . PrestashopProductsTemp::tableName() .
                   " WHERE fecha_descarga > DATE_SUB(NOW(), INTERVAL 24 HOUR)" .
                   " ORDER BY fecha_descarga DESC LIMIT 1";

            $data = $db->select($sql);
            if (!empty($data)) {
                $productsData = json_decode($data[0]['products_data'], true);
                if (is_array($productsData) && !empty($productsData)) {
                    Tools::log()->info("✓ Recuperados " . count($productsData) . " productos de sesión anterior");
                    $this->products = $productsData;
                    $this->productsLoaded = true;
                    $this->totalProducts = count($productsData);
                }
            }
        }

        // Calcular estadísticas y aplicar filtro
        if ($this->productsLoaded && !empty($this->products)) {
            $this->calculateStats();
            $this->applyFilter();
        }

        Tools::log()->info("================================");
    }

    /**
     * Obtiene el total de productos (petición AJAX)
     */
    private function getTotalProductsAction(): void
    {
        if (!$this->permissions->allowUpdate) {
            $this->returnJson(['error' => 'Sin permisos']);
            return;
        }

        if (!$this->config) {
            $this->returnJson(['error' => 'PrestaShop no configurado']);
            return;
        }

        try {
            $downloader = new ProductsDownload();
            $total = $downloader->getTotalProducts();

            $this->returnJson([
                'success' => true,
                'total' => $total
            ]);

        } catch (\Exception $e) {
            $this->returnJson(['error' => $e->getMessage()]);
        }
    }

    /**
     * Descarga productos por lotes (petición AJAX)
     */
    private function downloadProductsBatchAction(): void
    {
        if (!$this->permissions->allowUpdate) {
            $this->returnJson(['error' => 'Sin permisos']);
            return;
        }

        if (!$this->config) {
            $this->returnJson(['error' => 'PrestaShop no configurado']);
            return;
        }

        try {
            $offset = (int)$this->request->request->get('offset', 0);
            $limit = (int)$this->request->request->get('limit', 5); // Lotes de 5 productos (con atributos es más lento)

            Tools::log()->info("Descargando lote de productos: offset={$offset}, limit={$limit}");

            $downloader = new ProductsDownload();
            $result = $downloader->getAllProducts($offset, $limit);

            // Obtener productos de BD temporal
            $allProducts = PrestashopProductsTemp::getProducts();

            // Agregar nuevos productos
            $allProducts = array_merge($allProducts, $result['products']);

            // Guardar en BD temporal
            PrestashopProductsTemp::saveProducts($allProducts);

            Tools::log()->info("BD temporal actualizada: " . count($allProducts) . " productos totales guardados");

            $this->returnJson([
                'success' => true,
                'products' => $result['products'],
                'offset' => $offset,
                'downloaded' => count($allProducts),
                'in_batch' => count($result['products'])
            ]);

        } catch (\Exception $e) {
            Tools::log()->error('Error descargando lote: ' . $e->getMessage());
            $this->returnJson(['error' => $e->getMessage()]);
        }
    }

    /**
     * Devuelve respuesta JSON y termina la ejecución
     */
    private function returnJson(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        die();
    }

    /**
     * Muestra productos de BD temporal
     */
    private function showProductsAction(): void
    {
        $tempProducts = PrestashopProductsTemp::getProducts();
        $this->products = $tempProducts;
        $this->productsLoaded = true;
        $this->totalProducts = count($tempProducts);
    }

    /**
     * Limpia productos de BD temporal
     */
    private function clearProductsAction(): void
    {
        // Limpiar TODOS los registros de la tabla, no solo la sesión actual
        $db = new \FacturaScripts\Core\Base\DataBase();
        $sql = "DELETE FROM " . PrestashopProductsTemp::tableName();
        $db->exec($sql);

        Tools::log()->info("✓ Tabla de productos temporales limpiada completamente");

        $this->products = [];
        $this->productsLoaded = false;
        $this->totalProducts = 0;
    }

    /**
     * Importa los productos seleccionados
     */
    private function importSelectedAction(): void
    {
        if (!$this->permissions->allowUpdate) {
            Tools::log()->warning('No tienes permisos para importar productos');
            return;
        }

        // Obtener productos de BD temporal
        $allProducts = PrestashopProductsTemp::getProducts();
        if (empty($allProducts)) {
            Tools::log()->error('No hay productos descargados. Descarga los productos primero.');
            return;
        }

        // Obtener IDs seleccionados
        $selectedIds = $this->request->request->get('selected_products', []);

        // Si viene como string serializado de PHP, deserializar
        if (is_string($selectedIds)) {
            $selectedIds = @unserialize($selectedIds);
            if ($selectedIds === false) {
                $selectedIds = [];
            }
        }

        // Convertir índices a integers (vienen como strings)
        if (is_array($selectedIds)) {
            $selectedIds = array_map('intval', $selectedIds);
        }

        // DEBUG: Ver qué se recibe
        Tools::log()->info('===== DEBUG IMPORTACIÓN =====');
        Tools::log()->info('Selected IDs procesados: ' . json_encode($selectedIds));
        Tools::log()->info('Tipo de selectedIds: ' . gettype($selectedIds));
        Tools::log()->info('Count selectedIds: ' . (is_array($selectedIds) ? count($selectedIds) : 0));
        Tools::log()->info('Total productos en BD: ' . count($allProducts));
        Tools::log()->info('Índices disponibles: ' . implode(', ', array_keys(array_slice($allProducts, 0, 5))));
        Tools::log()->info('=============================');

        if (empty($selectedIds)) {
            Tools::log()->warning('No se seleccionó ningún producto para importar');
            return;
        }

        try {
            Tools::log()->info('========================================');
            Tools::log()->info('IMPORTANDO PRODUCTOS SELECCIONADOS');
            Tools::log()->info('========================================');

            $downloader = new ProductsDownload();
            $this->importedCount = 0;
            $this->errorCount = 0;

            foreach ($selectedIds as $index) {
                Tools::log()->info("Procesando índice: {$index}");

                if (!isset($allProducts[$index])) {
                    Tools::log()->warning("Índice {$index} no existe en allProducts");
                    continue;
                }

                $product = $allProducts[$index];

                // Verificar que tenga referencia
                if (empty($product['reference'])) {
                    Tools::log()->warning("Producto sin referencia omitido: {$product['name']}");
                    $this->errorCount++;
                    continue;
                }

                // Importar producto
                if ($downloader->importProduct($product)) {
                    $this->importedCount++;
                } else {
                    $this->errorCount++;
                }
            }

            Tools::log()->info('========================================');
            Tools::log()->info("IMPORTACIÓN COMPLETADA");
            Tools::log()->info("Productos importados: {$this->importedCount}");
            Tools::log()->info("Errores: {$this->errorCount}");
            Tools::log()->info('========================================');

            // Mostrar productos actualizados
            $this->products = $allProducts;
            $this->productsLoaded = true;
            $this->totalProducts = count($allProducts);

        } catch (\Exception $e) {
            Tools::log()->error('Error importando productos: ' . $e->getMessage());
        }
    }

    /**
     * Calcula estadísticas de productos
     */
    private function calculateStats(): void
    {
        $this->stats = [
            'importados' => 0,
            'ya_existen' => 0,
            'nuevos' => 0
        ];

        foreach ($this->products as $product) {
            if (!empty($product['imported'])) {
                $this->stats['importados']++;
            }

            if (!empty($product['exists'])) {
                $this->stats['ya_existen']++;
            } else {
                $this->stats['nuevos']++;
            }
        }
    }

    /**
     * Aplica filtro a productos
     */
    private function applyFilter(): void
    {
        $allProducts = $this->products;
        $filtered = [];

        foreach ($allProducts as $index => $product) {
            $include = false;

            switch ($this->filterStatus) {
                case 'todos':
                    $include = true;
                    break;

                case 'importados':
                    $include = !empty($product['imported']);
                    break;

                case 'ya_existen':
                    $include = !empty($product['exists']);
                    break;

                case 'nuevos':
                    $include = empty($product['exists']);
                    break;
            }

            if ($include) {
                $filtered[$index] = $product;
            }
        }

        $this->products = $filtered;
        $this->visibleProducts = count($filtered);
    }

    /**
     * Actualiza ventasinstock para productos seleccionados
     */
    private function updateVentaSinStockAction(): void
    {
        if (!$this->permissions->allowUpdate) {
            Tools::log()->warning('No tienes permisos para actualizar productos');
            return;
        }

        // Obtener IDs seleccionados
        $selectedIds = $this->request->request->get('selected_products', []);

        // Si viene como string serializado de PHP, deserializar
        if (is_string($selectedIds)) {
            $selectedIds = @unserialize($selectedIds);
            if ($selectedIds === false) {
                $selectedIds = [];
            }
        }

        // Convertir índices a integers
        if (is_array($selectedIds)) {
            $selectedIds = array_map('intval', $selectedIds);
        }

        if (empty($selectedIds)) {
            Tools::log()->warning('No se seleccionó ningún producto');
            return;
        }

        try {
            Tools::log()->info('========================================');
            Tools::log()->info('ACTUALIZANDO VENTA SIN STOCK');
            Tools::log()->info('========================================');

            // Cargar modelo Producto de FacturaScripts
            $productoModel = new \FacturaScripts\Dinamic\Model\Producto();
            $varianteModel = new \FacturaScripts\Dinamic\Model\Variante();

            $updated = 0;

            // Obtener productos de BD temporal
            $allProducts = PrestashopProductsTemp::getProducts();

            foreach ($selectedIds as $index) {
                if (!isset($allProducts[$index])) {
                    continue;
                }

                $product = $allProducts[$index];

                // Verificar que tenga referencia
                if (empty($product['reference'])) {
                    continue;
                }

                // Buscar producto en FacturaScripts por referencia
                $producto = $productoModel->get($product['reference']);

                if ($producto) {
                    // Actualizar ventasinstock
                    $producto->ventasinstock = true;

                    if ($producto->save()) {
                        // Actualizar también la variante
                        $variante = $varianteModel->get($product['reference']);
                        if ($variante) {
                            $variante->ventasinstock = true;
                            $variante->save();
                        }

                        $updated++;
                        Tools::log()->info("✓ Actualizado ventasinstock: {$product['reference']} - {$product['name']}");
                    }
                }
            }

            Tools::log()->info("========================================");
            Tools::log()->info("ACTUALIZACIÓN COMPLETADA");
            Tools::log()->info("Productos actualizados: {$updated}");
            Tools::log()->info("========================================");

            $this->importedCount = $updated;

        } catch (\Exception $e) {
            Tools::log()->error('Error actualizando ventasinstock: ' . $e->getMessage());
        }
    }
}
