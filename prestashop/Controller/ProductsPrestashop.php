<?php

namespace FacturaScripts\Plugins\Prestashop\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopConfig;
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
            case 'download-products':
                $this->downloadProductsAction();
                break;

            case 'import-selected':
                $this->importSelectedAction();
                break;
        }
    }

    /**
     * Descarga todos los productos de PrestaShop
     */
    private function downloadProductsAction(): void
    {
        if (!$this->permissions->allowUpdate) {
            Tools::log()->warning('No tienes permisos para descargar productos');
            return;
        }

        if (!$this->config) {
            Tools::log()->error('PrestaShop no está configurado');
            return;
        }

        try {
            Tools::log()->info('========================================');
            Tools::log()->info('DESCARGANDO PRODUCTOS DE PRESTASHOP');
            Tools::log()->info('========================================');

            $downloader = new ProductsDownload();
            $this->products = $downloader->getAllProducts();
            $this->totalProducts = count($this->products);
            $this->productsLoaded = true;

            // Guardar productos en sesión para poder importarlos después
            $this->saveToSession('prestashop_products', $this->products);

            Tools::log()->info("Se descargaron {$this->totalProducts} productos (con combinaciones expandidas)");

        } catch (\Exception $e) {
            Tools::log()->error('Error descargando productos: ' . $e->getMessage());
        }
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

        // Obtener productos de la sesión
        $allProducts = $this->loadFromSession('prestashop_products', []);
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
