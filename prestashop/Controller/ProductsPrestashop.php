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

        // Cargar configuración
        $this->config = PrestashopConfig::getActive();

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
        }

        // Limpiar productos antiguos (más de 24h)
        PrestashopProductsTemp::cleanOld();

        // Debug: Verificar productos en BD temporal
        Tools::log()->info("===== DEBUG PRODUCTOS TEMP =====");
        Tools::log()->info("Session ID: " . session_id());
        $tempProducts = PrestashopProductsTemp::getProducts();
        Tools::log()->info("Productos en BD temporal: " . (empty($tempProducts) ? 'NO' : 'SI - ' . count($tempProducts)));
        if (!empty($tempProducts)) {
            Tools::log()->info("Primer producto: " . json_encode($tempProducts[0] ?? 'vacio'));
        }
        Tools::log()->info("================================");

        // Si hay productos, mostrarlos
        if (!empty($tempProducts)) {
            $this->products = $tempProducts;
            $this->productsLoaded = true;
            $this->totalProducts = count($tempProducts);
            Tools::log()->info("✓ Cargados " . $this->totalProducts . " productos de BD temporal para mostrar");
        } else {
            Tools::log()->warning("✗ No hay productos en BD temporal para mostrar");
        }
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
        PrestashopProductsTemp::clearSession();
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
                if (!isset($allProducts[$index])) {
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
}
