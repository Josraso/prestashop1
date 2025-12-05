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
            $processed = 0;
            $errors = 0;
            foreach ($productIds as $productId) {
                try {
                    Tools::log()->info("[ProductsDownload] Procesando producto ID {$productId}...");

                    $productDetails = $this->getProductDetails($productId);
                    if ($productDetails) {
                        // Expandir combinaciones
                        $expandedProducts = $this->expandProductCombinations($productDetails);
                        $products = array_merge($products, $expandedProducts);
                        $processed++;
                        Tools::log()->info("[ProductsDownload] ✓ Producto ID {$productId} procesado: " . count($expandedProducts) . " variante(s)");
                    } else {
                        Tools::log()->warning("[ProductsDownload] Producto ID {$productId} sin detalles disponibles");
                        $errors++;
                    }
                } catch (\Exception $e) {
                    Tools::log()->error("[ProductsDownload] Error obteniendo producto ID {$productId}: " . $e->getMessage());
                    Tools::log()->error("[ProductsDownload] Stack trace: " . $e->getTraceAsString());
                    $errors++;
                    continue; // Continuar con el siguiente producto
                }
            }

            Tools::log()->info("[ProductsDownload] Resumen del lote: {$processed} productos procesados, {$errors} errores, " . count($products) . " variantes totales");

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

            // Referencia
            $reference = (string)$product->reference;

            // Estado (activo/inactivo)
            $active = (int)$product->active === 1;

            // Imagen por defecto (si existe)
            $imageUrl = $this->getProductImageUrl($productId, (int)$product->id_default_image);

            // Obtener combinaciones
            $combinations = $this->getProductCombinations($productId);

            // Stock: Si tiene combinaciones, el stock se obtiene de cada combinación
            // Si NO tiene combinaciones, obtener stock desde stock_availables
            $stock = 0;
            if (empty($combinations)) {
                $stock = $this->getStockForProduct($productId);
            }

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

                // Obtener stock real desde stock_availables
                $quantity = $this->getStockForCombination($productId, $comboId);

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
     * Obtiene el stock real de una combinación desde stock_availables
     *
     * @param int $productId
     * @param int $combinationId
     * @return int
     */
    private function getStockForCombination(int $productId, int $combinationId): int
    {
        try {
            $webService = $this->connection->getWebService();

            $params = [
                'filter[id_product]' => $productId,
                'filter[id_product_attribute]' => $combinationId,
                'display' => '[quantity]'
            ];

            $xmlString = @$webService->get('stock_availables', null, null, $params);
            if ($xmlString === false) {
                Tools::log()->debug("No se pudo obtener stock_availables para producto {$productId}, combinación {$combinationId}");
                return 0;
            }

            $xml = @simplexml_load_string($xmlString);
            if ($xml === false) {
                Tools::log()->debug("Error parseando XML de stock para combinación {$combinationId}");
                return 0;
            }

            if (isset($xml->stock_availables->stock_available)) {
                $stock = $xml->stock_availables->stock_available;

                // Si hay múltiples resultados, tomar el primero
                if (is_array($stock) || $stock instanceof \Traversable) {
                    foreach ($stock as $s) {
                        $quantity = (int)$s->quantity;
                        Tools::log()->debug("Stock para combinación {$combinationId}: {$quantity}");
                        return $quantity;
                    }
                } else {
                    $quantity = (int)$stock->quantity;
                    Tools::log()->debug("Stock para combinación {$combinationId}: {$quantity}");
                    return $quantity;
                }
            }

            Tools::log()->debug("No se encontró stock_available para combinación {$combinationId}, usando 0");
            return 0;

        } catch (\Exception $e) {
            Tools::log()->warning("Excepción obteniendo stock para combinación {$combinationId}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Obtiene el stock real de un producto sin combinaciones desde stock_availables
     *
     * @param int $productId
     * @return int
     */
    private function getStockForProduct(int $productId): int
    {
        try {
            $webService = $this->connection->getWebService();

            $params = [
                'filter[id_product]' => $productId,
                'filter[id_product_attribute]' => 0,  // 0 = producto sin combinaciones
                'display' => '[quantity]'
            ];

            $xmlString = @$webService->get('stock_availables', null, null, $params);
            if ($xmlString === false) {
                Tools::log()->debug("No se pudo obtener stock_availables para producto {$productId}");
                return 0;
            }

            $xml = @simplexml_load_string($xmlString);
            if ($xml === false) {
                Tools::log()->debug("Error parseando XML de stock para producto {$productId}");
                return 0;
            }

            if (isset($xml->stock_availables->stock_available)) {
                $stock = $xml->stock_availables->stock_available;

                // Si hay múltiples resultados, tomar el primero
                if (is_array($stock) || $stock instanceof \Traversable) {
                    foreach ($stock as $s) {
                        $quantity = (int)$s->quantity;
                        Tools::log()->debug("Stock para producto {$productId}: {$quantity}");
                        return $quantity;
                    }
                } else {
                    $quantity = (int)$stock->quantity;
                    Tools::log()->debug("Stock para producto {$productId}: {$quantity}");
                    return $quantity;
                }
            }

            Tools::log()->debug("No se encontró stock_available para producto {$productId}, usando 0");
            return 0;

        } catch (\Exception $e) {
            Tools::log()->warning("Excepción obteniendo stock para producto {$productId}: " . $e->getMessage());
            return 0;
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
     * @return string|null Nombre del archivo de la imagen guardada
     */
    public function downloadImage(string $imageUrl, string $reference): ?string
    {
        if (empty($imageUrl)) {
            Tools::log()->warning("URL de imagen vacía para referencia: {$reference}");
            return null;
        }

        try {
            // Directorio donde se guardarán las imágenes
            $uploadDir = \FS_FOLDER . '/MyFiles/Product';

            // Crear directorio si no existe
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    Tools::log()->error("No se pudo crear directorio: {$uploadDir}");
                    return null;
                }
                chmod($uploadDir, 0755);
                Tools::log()->info("Directorio creado: {$uploadDir}");
            }

            // Verificar permisos de escritura
            if (!is_writable($uploadDir)) {
                Tools::log()->error("Directorio sin permisos de escritura: {$uploadDir}");
                return null;
            }

            Tools::log()->info("Descargando imagen desde: {$imageUrl}");

            // Descargar imagen con contexto para manejar SSL
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'FacturaScripts-Prestashop-Plugin'
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);

            $imageData = @file_get_contents($imageUrl, false, $context);
            if ($imageData === false) {
                Tools::log()->error("Error descargando imagen desde: {$imageUrl}");
                return null;
            }

            // Verificar que se descargó algo
            $fileSize = strlen($imageData);
            if ($fileSize < 100) {
                Tools::log()->error("Imagen descargada demasiado pequeña ({$fileSize} bytes): {$imageUrl}");
                return null;
            }

            Tools::log()->info("Imagen descargada correctamente: {$fileSize} bytes");

            // Detectar tipo MIME real de la imagen
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($imageData);

            // Determinar extensión basada en MIME type
            $extension = 'jpg'; // Por defecto
            switch ($mimeType) {
                case 'image/jpeg':
                    $extension = 'jpg';
                    break;
                case 'image/png':
                    $extension = 'png';
                    break;
                case 'image/gif':
                    $extension = 'gif';
                    break;
                case 'image/webp':
                    $extension = 'webp';
                    break;
                default:
                    Tools::log()->warning("Tipo MIME desconocido: {$mimeType}, usando .jpg");
            }

            Tools::log()->info("Tipo MIME detectado: {$mimeType}, extensión: {$extension}");

            // Nombre del archivo (sanitizar referencia y hacerlo único)
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $reference);
            $timestamp = time();
            $filename = $safeName . '_' . $timestamp . '.' . $extension;
            $localPath = $uploadDir . '/' . $filename;

            // Si el archivo ya existe, añadir sufijo
            $counter = 1;
            while (file_exists($localPath) && $counter < 100) {
                $filename = $safeName . '_' . $timestamp . '_' . $counter . '.' . $extension;
                $localPath = $uploadDir . '/' . $filename;
                $counter++;
            }

            Tools::log()->info("Guardando imagen como: {$filename}");

            // Guardar imagen
            if (file_put_contents($localPath, $imageData) === false) {
                Tools::log()->error("Error guardando imagen en: {$localPath}");
                return null;
            }

            // Establecer permisos correctos
            chmod($localPath, 0644);

            // Verificar que el archivo existe y tiene el tamaño correcto
            if (!file_exists($localPath)) {
                Tools::log()->error("Archivo de imagen no existe después de guardar: {$localPath}");
                return null;
            }

            $savedSize = filesize($localPath);
            if ($savedSize != $fileSize) {
                Tools::log()->warning("Tamaño del archivo guardado ({$savedSize}) != descargado ({$fileSize})");
            }

            Tools::log()->info("✓ Imagen guardada correctamente: {$filename} ({$savedSize} bytes, permisos: 0644)");

            // Retornar solo el nombre del archivo (FacturaScripts espera esto)
            return $filename;

        } catch (\Exception $e) {
            Tools::log()->error("Excepción descargando imagen para {$reference}: " . $e->getMessage());
            Tools::log()->error("Stack trace: " . $e->getTraceAsString());
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
                $producto->referencia = $reference; // ← REFERENCIA EN EL PRODUCTO
                $producto->descripcion = $productData['name'];
                $producto->precio = $productData['price_with_tax'];
                $producto->nostock = false;
                $producto->ventasinstock = false;
                $producto->bloqueado = !$productData['active'];
                $producto->codimpuesto = 'IVA21';

                // Descargar y actualizar imagen
                if (!empty($productData['image_url'])) {
                    Tools::log()->info("Intentando descargar imagen para: {$reference}");
                    $imagePath = $this->downloadImage($productData['image_url'], $reference);
                    if ($imagePath) {
                        $producto->imagen = $imagePath;
                        Tools::log()->info("Campo imagen asignado: {$imagePath}");
                    } else {
                        Tools::log()->warning("No se pudo descargar imagen para: {$reference}");
                    }
                } else {
                    Tools::log()->info("No hay URL de imagen para: {$reference}");
                }

                // Guardar producto
                if (!$producto->save()) {
                    Tools::log()->error("Error actualizando producto: {$reference}");
                    return false;
                }

                Tools::log()->info("Producto guardado. Imagen en BD: " . ($producto->imagen ?? 'NULL'));

                // Recargar la variante para asegurar datos frescos
                if (!$variante->loadFromCode('', [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('referencia', $reference)])) {
                    Tools::log()->error("No se pudo recargar variante después de guardar producto: {$reference}");
                    return false;
                }

                // Actualizar stock y precio en la variante
                $variante->stockfis = $productData['stock'];
                $variante->precio = $productData['price_with_tax'];
                $variante->coste = 0; // Resetear coste si es necesario

                if (!$variante->save()) {
                    Tools::log()->error("Error actualizando stock de variante: {$reference}");
                    return false;
                }

                // Verificar que se guardó correctamente
                $varianteCheck = new Variante();
                if ($varianteCheck->loadFromCode('', [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('referencia', $reference)])) {
                    Tools::log()->info("Verificación BD - Stock: {$varianteCheck->stockfis}, Precio: {$varianteCheck->precio}, ID Producto: {$varianteCheck->idproducto}");
                }

                Tools::log()->info("✓ Producto actualizado: {$reference} | Stock: {$productData['stock']} | Precio: {$productData['price_with_tax']} | Bloqueado: " . ($producto->bloqueado ? 'SÍ' : 'NO'));

            } else {
                // CREAR NUEVO PRODUCTO
                Tools::log()->info("Creando nuevo producto: {$reference}");

                $producto = new Producto();
                $producto->referencia = $reference; // ← REFERENCIA EN EL PRODUCTO
                $producto->descripcion = $productData['name'];
                $producto->precio = $productData['price_with_tax'];
                $producto->nostock = false;
                $producto->ventasinstock = false;
                $producto->bloqueado = !$productData['active'];
                $producto->codimpuesto = 'IVA21';

                // Descargar imagen antes de guardar
                if (!empty($productData['image_url'])) {
                    Tools::log()->info("Intentando descargar imagen para nuevo producto: {$reference}");
                    $imagePath = $this->downloadImage($productData['image_url'], $reference);
                    if ($imagePath) {
                        $producto->imagen = $imagePath;
                        Tools::log()->info("Campo imagen asignado a nuevo producto: {$imagePath}");
                    } else {
                        Tools::log()->warning("No se pudo descargar imagen para nuevo producto: {$reference}");
                    }
                } else {
                    Tools::log()->info("No hay URL de imagen para nuevo producto: {$reference}");
                }

                // Guardar producto (esto crea automáticamente una variante)
                if (!$producto->save()) {
                    Tools::log()->error("Error creando producto: {$reference}");
                    return false;
                }

                Tools::log()->info("Nuevo producto guardado. Imagen en BD: " . ($producto->imagen ?? 'NULL'));

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
                $variante->coste = 0;

                if (!$variante->save()) {
                    Tools::log()->error("Error asignando referencia a variante: {$reference}");
                    return false;
                }

                Tools::log()->info("Variante guardada. ID: {$variante->idvariante}, Ref: {$variante->referencia}");

                // Verificar que se guardó correctamente
                $varianteCheck = new Variante();
                if ($varianteCheck->loadFromCode('', [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('referencia', $reference)])) {
                    Tools::log()->info("Verificación BD - Stock: {$varianteCheck->stockfis}, Precio: {$varianteCheck->precio}, ID Producto: {$varianteCheck->idproducto}");
                } else {
                    Tools::log()->warning("ADVERTENCIA: No se pudo verificar variante recién creada para: {$reference}");
                }

                Tools::log()->info("✓ Producto creado: {$reference} | Stock: {$productData['stock']} | Precio: {$productData['price_with_tax']} | Bloqueado: " . ($producto->bloqueado ? 'SÍ' : 'NO'));
            }

            return true;

        } catch (\Exception $e) {
            Tools::log()->error("Error importando producto {$productData['reference']}: " . $e->getMessage());
            return false;
        }
    }
}
