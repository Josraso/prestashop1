<?php

namespace FacturaScripts\Plugins\Prestashop\Lib\Actions;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Plugins\Prestashop\Lib\PrestashopConnection;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopConfig;

/**
 * Clase para descargar productos de PrestaShop y gestionarlos en FacturaScripts
 */
class ProductsDownload
{
    /** @var PrestashopConnection */
    private $connection;

    /** @var PrestashopConfig */
    private $config;

    public function __construct()
    {
        $this->config = PrestashopConfig::getActive();
        $this->connection = new PrestashopConnection($this->config);
    }

    /**
     * Obtiene productos de PrestaShop por lotes con sus combinaciones expandidas
     *
     * @param int $offset Desde qué producto empezar
     * @param int $limit Cuántos productos obtener
     * @return array Array con 'products', 'offset', 'limit'
     */
    public function getAllProducts(int $offset = 0, int $limit = 50): array
    {
        if (!$this->config) {
            Tools::log()->error('PrestaShop: Configuración no encontrada');
            return ['products' => []];
        }

        if (!$this->connection->isConnected()) {
            Tools::log()->error('PrestaShop: No se pudo conectar con la tienda');
            return ['products' => []];
        }

        Tools::log()->info("[ProductsDownload] Obteniendo productos (offset: {$offset}, limit: {$limit})...");

        try {
            $webService = $this->connection->getWebService();
            $products = [];

            // Obtener IDs de productos con paginación
            $params = [
                'display' => '[id]',
                'limit' => "{$offset},{$limit}"
            ];

            $xmlString = $webService->get('products', null, null, $params);
            $xml = simplexml_load_string($xmlString);

            if (!isset($xml->products->product)) {
                Tools::log()->warning('No se encontraron productos en PrestaShop');
                return ['products' => []];
            }

            $productIds = [];
            foreach ($xml->products->product as $product) {
                $productIds[] = (int)$product->id;
            }

            Tools::log()->info('[ProductsDownload] Encontrados ' . count($productIds) . ' productos en este lote');

            // Obtener detalles completos de cada producto
            foreach ($productIds as $productId) {
                try {
                    $productDetails = $this->getProductDetails($productId);
                    if ($productDetails) {
                        // Expandir combinaciones
                        $expandedProducts = $this->expandProductCombinations($productDetails);
                        $products = array_merge($products, $expandedProducts);
                    }
                } catch (\Exception $e) {
                    Tools::log()->error("Error obteniendo producto ID {$productId}: " . $e->getMessage());
                    continue;
                }
            }

            Tools::log()->info('[ProductsDownload] Total de productos con combinaciones expandidas en este lote: ' . count($products));

            return [
                'products' => $products,
                'offset' => $offset,
                'limit' => $limit
            ];

        } catch (\Exception $e) {
            Tools::log()->error('Error obteniendo productos: ' . $e->getMessage());
            return ['products' => []];
        }
    }

    /**
     * Obtiene el total de productos en PrestaShop
     *
     * @return int
     */
    public function getTotalProducts(): int
    {
        if (!$this->config || !$this->connection->isConnected()) {
            return 0;
        }

        try {
            $webService = $this->connection->getWebService();

            // Obtener todos los IDs sin límite para contar el total
            $params = [
                'display' => '[id]'
            ];

            $xmlString = $webService->get('products', null, null, $params);
            $xml = simplexml_load_string($xmlString);

            if (!isset($xml->products)) {
                return 0;
            }

            // Contar los productos que vienen en la respuesta
            if (isset($xml->products->product)) {
                $count = 0;
                foreach ($xml->products->product as $product) {
                    $count++;
                }
                return $count;
            }

            return 0;

        } catch (\Exception $e) {
            Tools::log()->error('Error obteniendo total de productos: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Obtiene los detalles completos de un producto
     *
     * @param int $productId
     * @return array|null
     */
    private function getProductDetails(int $productId): ?array
    {
        try {
            $webService = $this->connection->getWebService();

            // Obtener producto completo usando filtro
            $params = [
                'filter[id]' => '[' . $productId . ']',
                'display' => 'full',
                'limit' => 1
            ];

            $xmlString = $webService->get('products', null, null, $params);
            $xml = simplexml_load_string($xmlString);

            // Con filtro, el resultado viene en <products><product>
            if (!isset($xml->products->product)) {
                return null;
            }

            // Tomar el primer producto del resultado
            $product = $xml->products->product;
            if (is_array($product) || $product instanceof \Traversable) {
                foreach ($product as $p) {
                    $product = $p;
                    break;
                }
            }

            // Extraer nombre (primer idioma disponible)
            $name = $this->extractMultilangField($product->name);

            // Precio base
            $price = (float)$product->price;

            // Stock (cantidad disponible)
            $stock = (int)$product->quantity;

            // Referencia
            $reference = (string)$product->reference;

            // Estado (activo/inactivo)
            $active = (int)$product->active === 1;

            // Imagen por defecto (si existe)
            $imageUrl = $this->getProductImageUrl($productId, (int)$product->id_default_image);

            // Obtener combinaciones
            $combinations = $this->getProductCombinations($productId);

            return [
                'id' => $productId,
                'name' => $name,
                'reference' => $reference,
                'price' => $price,
                'stock' => $stock,
                'active' => $active,
                'image_url' => $imageUrl,
                'combinations' => $combinations
            ];

        } catch (\Exception $e) {
            Tools::log()->error("Error obteniendo detalles del producto {$productId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtiene las combinaciones de un producto con sus atributos
     *
     * @param int $productId
     * @return array
     */
    private function getProductCombinations(int $productId): array
    {
        try {
            $webService = $this->connection->getWebService();

            $params = [
                'filter[id_product]' => $productId,
                'display' => '[id,reference,price,quantity]'
            ];

            $xmlString = $webService->get('combinations', null, null, $params);
            $xml = simplexml_load_string($xmlString);

            if (!isset($xml->combinations->combination)) {
                return [];
            }

            $combinations = [];
            foreach ($xml->combinations->combination as $combo) {
                $comboId = (int)$combo->id;
                $reference = (string)$combo->reference;
                $priceImpact = (float)$combo->price;
                $quantity = (int)$combo->quantity;

                // Obtener atributos de esta combinación (necesario para identificar)
                $attributes = $this->getCombinationAttributeValues($comboId);

                $combinations[] = [
                    'id' => $comboId,
                    'reference' => $reference,
                    'price_impact' => $priceImpact,
                    'quantity' => $quantity,
                    'attributes' => $attributes
                ];
            }

            return $combinations;

        } catch (\Exception $e) {
            Tools::log()->error("Error obteniendo combinaciones del producto {$productId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene los valores de atributos de una combinación (ej: "Talla M", "Color Rojo")
     *
     * @param int $combinationId
     * @return array
     */
    private function getCombinationAttributeValues(int $combinationId): array
    {
        try {
            $webService = $this->connection->getWebService();

            // Obtener combinación completa con asociaciones usando filtro
            $params = [
                'filter[id]' => '[' . $combinationId . ']',
                'display' => 'full',
                'limit' => 1
            ];

            $xmlString = $webService->get('combinations', null, null, $params);
            $xml = simplexml_load_string($xmlString);

            // Con filtro viene en <combinations><combination>
            if (!isset($xml->combinations->combination)) {
                return [];
            }

            // Tomar la primera combinación
            $combination = $xml->combinations->combination;
            if (is_array($combination) || $combination instanceof \Traversable) {
                foreach ($combination as $c) {
                    $combination = $c;
                    break;
                }
            }

            if (!isset($combination->associations->product_option_values->product_option_value)) {
                return [];
            }

            $attributes = [];
            foreach ($combination->associations->product_option_values->product_option_value as $optionValue) {
                $optionValueId = (int)$optionValue->id;

                // Obtener el nombre del valor de atributo
                $valueName = $this->getProductOptionValueName($optionValueId);
                if ($valueName) {
                    $attributes[] = $valueName;
                }
            }

            return $attributes;

        } catch (\Exception $e) {
            Tools::log()->error("Error obteniendo atributos de combinación {$combinationId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene el nombre de un valor de opción de producto
     *
     * @param int $optionValueId
     * @return string|null
     */
    private function getProductOptionValueName(int $optionValueId): ?string
    {
        try {
            $webService = $this->connection->getWebService();

            $params = [
                'filter[id]' => '[' . $optionValueId . ']',
                'display' => 'full',
                'limit' => 1
            ];

            $xmlString = $webService->get('product_option_values', null, null, $params);
            $xml = simplexml_load_string($xmlString);

            // Con filtro viene en <product_option_values><product_option_value>
            if (!isset($xml->product_option_values->product_option_value)) {
                return null;
            }

            // Tomar el primer valor
            $optionValue = $xml->product_option_values->product_option_value;
            if (is_array($optionValue) || $optionValue instanceof \Traversable) {
                foreach ($optionValue as $v) {
                    $optionValue = $v;
                    break;
                }
            }

            if (!isset($optionValue->name)) {
                return null;
            }

            return $this->extractMultilangField($optionValue->name);

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Expande un producto con sus combinaciones como productos independientes
     *
     * @param array $productDetails
     * @return array
     */
    private function expandProductCombinations(array $productDetails): array
    {
        $products = [];

        // Si el producto tiene combinaciones, expandir cada una
        if (!empty($productDetails['combinations'])) {
            foreach ($productDetails['combinations'] as $combo) {
                // Calcular precio final con IVA (precio base + impacto de combinación) * 1.21
                $priceWithTax = ($productDetails['price'] + $combo['price_impact']) * 1.21;

                // Concatenar nombre con atributos: "Producto - Attr1 - Attr2"
                $fullName = $productDetails['name'];
                if (!empty($combo['attributes'])) {
                    $fullName .= ' - ' . implode(' - ', $combo['attributes']);
                }

                // Usar referencia de la combinación, o generar una si está vacía
                $reference = !empty($combo['reference']) ? $combo['reference'] : $productDetails['reference'] . '-' . $combo['id'];

                // Verificar si el producto ya existe en FacturaScripts
                $exists = $this->checkProductExists($reference);

                $products[] = [
                    'ps_product_id' => $productDetails['id'],
                    'ps_combination_id' => $combo['id'],
                    'reference' => $reference,
                    'name' => $fullName,
                    'price_with_tax' => round($priceWithTax, 2),
                    'stock' => $combo['quantity'],
                    'image_url' => $productDetails['image_url'],
                    'active' => $productDetails['active'],
                    'has_combination' => true,
                    'exists' => $exists
                ];
            }
        } else {
            // Producto sin combinaciones - incluirlo tal cual
            $priceWithTax = $productDetails['price'] * 1.21;

            // Verificar si el producto ya existe en FacturaScripts
            $exists = $this->checkProductExists($productDetails['reference']);

            $products[] = [
                'ps_product_id' => $productDetails['id'],
                'ps_combination_id' => null,
                'reference' => $productDetails['reference'],
                'name' => $productDetails['name'],
                'price_with_tax' => round($priceWithTax, 2),
                'stock' => $productDetails['stock'],
                'image_url' => $productDetails['image_url'],
                'active' => $productDetails['active'],
                'has_combination' => false,
                'exists' => $exists
            ];
        }

        return $products;
    }

    /**
     * Verifica si un producto existe en FacturaScripts por su referencia
     *
     * @param string $reference
     * @return bool
     */
    private function checkProductExists(string $reference): bool
    {
        if (empty($reference)) {
            return false;
        }

        try {
            $variante = new Variante();
            return $variante->loadFromCode('', [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('referencia', $reference)]);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Obtiene la URL de la imagen de un producto
     *
     * @param int $productId
     * @param int $imageId
     * @return string|null
     */
    private function getProductImageUrl(int $productId, int $imageId): ?string
    {
        if ($imageId <= 0) {
            return null;
        }

        // URL del formato: https://tienda.com/api/images/products/{productId}/{imageId}
        $shopUrl = rtrim($this->config->shop_url, '/');
        return "{$shopUrl}/api/images/products/{$productId}/{$imageId}?ws_key={$this->config->api_key}";
    }

    /**
     * Extrae el valor de un campo multiidioma (toma el primero disponible)
     *
     * @param \SimpleXMLElement $field
     * @return string
     */
    private function extractMultilangField(\SimpleXMLElement $field): string
    {
        if (isset($field->language)) {
            // Si hay múltiples idiomas, tomar el primero
            if (is_array($field->language) || $field->language instanceof \Traversable) {
                foreach ($field->language as $lang) {
                    return (string)$lang;
                }
            } else {
                return (string)$field->language;
            }
        }

        return (string)$field;
    }

    /**
     * Descarga una imagen desde PrestaShop y la guarda localmente
     *
     * @param string $imageUrl
     * @param string $reference Referencia del producto (para nombrar el archivo)
     * @return string|null Ruta local de la imagen guardada
     */
    public function downloadImage(string $imageUrl, string $reference): ?string
    {
        if (empty($imageUrl)) {
            return null;
        }

        try {
            // Directorio donde se guardarán las imágenes
            $uploadDir = \FS_FOLDER . '/MyFiles/Product';

            // Crear directorio si no existe
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            // Nombre del archivo (sanitizar referencia)
            $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $reference) . '.jpg';
            $localPath = $uploadDir . '/' . $filename;

            // Descargar imagen
            $imageData = file_get_contents($imageUrl);
            if ($imageData === false) {
                Tools::log()->error("No se pudo descargar la imagen: {$imageUrl}");
                return null;
            }

            // Guardar imagen
            file_put_contents($localPath, $imageData);

            Tools::log()->info("Imagen descargada: {$filename}");
            return $filename; // Retornar solo el nombre del archivo

        } catch (\Exception $e) {
            Tools::log()->error("Error descargando imagen: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Importa un producto a FacturaScripts (crea o actualiza)
     *
     * @param array $productData Datos del producto
     * @return bool
     */
    public function importProduct(array $productData): bool
    {
        try {
            $reference = $productData['reference'];

            // Buscar si el producto ya existe por referencia
            $variante = new Variante();
            $productoExists = $variante->loadFromCode('', [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('referencia', $reference)]);

            if ($productoExists) {
                // ACTUALIZAR PRODUCTO EXISTENTE
                Tools::log()->info("Actualizando producto existente: {$reference}");

                // Cargar el producto asociado
                $producto = new Producto();
                if (!$producto->loadFromCode($variante->idproducto)) {
                    Tools::log()->error("No se pudo cargar el producto con ID: {$variante->idproducto}");
                    return false;
                }

                // Actualizar datos del producto
                $producto->descripcion = $productData['name'];
                $producto->precio = $productData['price_with_tax'];
                $producto->nostock = false;
                $producto->ventasinstock = false;
                $producto->bloqueado = !$productData['active'];
                $producto->codimpuesto = 'IVA21';

                // Descargar y actualizar imagen
                if (!empty($productData['image_url'])) {
                    $imagePath = $this->downloadImage($productData['image_url'], $reference);
                    if ($imagePath) {
                        $producto->imagen = $imagePath;
                        Tools::log()->info("Imagen actualizada para: {$reference}");
                    }
                }

                // Guardar producto
                if (!$producto->save()) {
                    Tools::log()->error("Error actualizando producto: {$reference}");
                    return false;
                }

                // Actualizar stock en la variante
                $variante->stockfis = $productData['stock'];
                $variante->precio = $productData['price_with_tax'];
                if (!$variante->save()) {
                    Tools::log()->error("Error actualizando stock de variante: {$reference}");
                    return false;
                }

                Tools::log()->info("✓ Producto actualizado: {$reference} | Stock: {$productData['stock']} | Precio: {$productData['price_with_tax']}");

            } else {
                // CREAR NUEVO PRODUCTO
                Tools::log()->info("Creando nuevo producto: {$reference}");

                $producto = new Producto();
                $producto->descripcion = $productData['name'];
                $producto->precio = $productData['price_with_tax'];
                $producto->nostock = false;
                $producto->ventasinstock = false;
                $producto->bloqueado = !$productData['active'];
                $producto->codimpuesto = 'IVA21';

                // Descargar imagen antes de guardar
                if (!empty($productData['image_url'])) {
                    $imagePath = $this->downloadImage($productData['image_url'], $reference);
                    if ($imagePath) {
                        $producto->imagen = $imagePath;
                        Tools::log()->info("Imagen descargada para nuevo producto: {$reference}");
                    }
                }

                // Guardar producto (esto crea automáticamente una variante)
                if (!$producto->save()) {
                    Tools::log()->error("Error creando producto: {$reference}");
                    return false;
                }

                // Obtener la variante auto-creada y asignarle la referencia y stock
                $variantes = $producto->getVariants();
                if (empty($variantes)) {
                    Tools::log()->error("No se creó variante automática para: {$reference}");
                    return false;
                }

                $variante = $variantes[0];
                $variante->referencia = $reference;
                $variante->stockfis = $productData['stock'];
                $variante->precio = $productData['price_with_tax'];

                if (!$variante->save()) {
                    Tools::log()->error("Error asignando referencia a variante: {$reference}");
                    return false;
                }

                Tools::log()->info("✓ Producto creado: {$reference} | Stock: {$productData['stock']} | Precio: {$productData['price_with_tax']}");
            }

            return true;

        } catch (\Exception $e) {
            Tools::log()->error("Error importando producto {$productData['reference']}: " . $e->getMessage());
            return false;
        }
    }
}
