<?php

namespace FacturaScripts\Plugins\Prestashop\Lib\Actions;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\LineaAlbaranCliente;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Plugins\Prestashop\Lib\PrestashopConnection;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopConfig;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopImportLog;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopPaymentMap;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopTaxMap;

/**
 * Clase para descargar pedidos de PrestaShop e importarlos como albaranes
 */
class OrdersDownload
{
    /** @var PrestashopConnection */
    private $connection;

    /** @var PrestashopConfig */
    private $config;

    /** @var array */
    private $importLog = [];

    public function __construct()
    {
        $this->config = PrestashopConfig::getActive();
        $this->connection = new PrestashopConnection($this->config);
    }

    /**
     * Proceso batch para importar pedidos
     *
     * @param string $origen Origen de la importación (cron, manual, webhook)
     */
    public function batch(string $origen = 'cron'): void
    {
        Tools::log()->info('[OrdersDownload::batch] Método batch() iniciado - VERSIÓN ACTUALIZADA 2025-11-25');

        if (!$this->config) {
            Tools::log()->error('PrestaShop: Configuración no encontrada');
            return;
        }

        Tools::log()->info('[OrdersDownload::batch] Configuración encontrada - URL: ' . $this->config->shop_url);

        // VALIDACIÓN: Verificar conexión antes de procesar
        if (!$this->connection->isConnected()) {
            Tools::log()->error('PrestaShop: No se pudo conectar con la tienda. Verifica la URL y API Key en la configuración.');
            return;
        }

        Tools::log()->info('[OrdersDownload::batch] Conexión verificada correctamente');

        // Verificar que hay estados seleccionados
        $estados = $this->config->getEstadosArray();
        if (empty($estados)) {
            Tools::log()->warning('No hay estados de pedido seleccionados para importar. Configura los estados en la página de configuración.');
            return;
        }

        Tools::log()->info('[OrdersDownload::batch] Estados configurados: ' . implode(', ', $estados));

        // VALIDACIÓN: Probar conexión obteniendo 1 pedido
        Tools::log()->info('[OrdersDownload::batch] Probando conexión con API...');
        try {
            $testOrders = $this->connection->getOrders(1, null);
            if ($testOrders === false || $testOrders === null) {
                Tools::log()->error('PrestaShop: Error al obtener pedidos de la API. Verifica permisos del API Key.');
                return;
            }
            Tools::log()->info('[OrdersDownload::batch] Prueba de API exitosa - Pedidos de prueba: ' . count($testOrders));
        } catch (\Exception $e) {
            Tools::log()->error('PrestaShop: Error de conexión - ' . $e->getMessage());
            return;
        }

        try {
            $imported = 0;
            $errors = 0;
            $skipped = 0;

            // Fecha fija desde la que siempre buscar
            $importSinceDate = null;
            if (!empty($this->config->import_since_date)) {
                $importSinceDate = $this->config->import_since_date;
                Tools::log()->info("Buscando pedidos desde fecha: {$importSinceDate}");
            }

            // Filtro de ID mínimo (SIEMPRE usado)
            $importSinceId = (int)$this->config->import_since_id;

            // IMPORTANTE: Si el usuario cambió la fecha a más antigua, permitir retroceder
            // Para ello, buscar si hay pedidos ANTES del import_since_id actual que cumplan la nueva fecha
            if (!empty($importSinceDate) && $importSinceId > 0) {
                Tools::log()->info("Verificando si hay pedidos anteriores al ID {$importSinceId} que cumplan con la fecha {$importSinceDate}...");

                // NO pasar customFilters - la API ya filtra por estado y no acepta más filtros complejos
                // Obtenemos pedidos del estado configurado y filtramos en PHP
                $testOrders = $this->connection->getOrders(100, null);

                if ($testOrders === false || $testOrders === null) {
                    Tools::log()->error("Error al obtener pedidos para verificación de retroceso");
                } else {
                    // Filtrar manualmente en PHP: ID < import_since_id Y fecha >= import_since_date
                    $oldOrders = array_filter($testOrders, function($order) use ($importSinceId, $importSinceDate) {
                        $orderId = (int)$order->id;
                        $orderDate = substr((string)$order->date_add, 0, 10); // YYYY-MM-DD
                        return $orderId < $importSinceId && $orderDate >= $importSinceDate;
                    });

                    if (!empty($oldOrders)) {
                        Tools::log()->warning("⚠ Se encontraron " . count($oldOrders) . " pedidos antiguos que cumplen con la nueva fecha.");
                        Tools::log()->warning("⚠ Reseteando import_since_id a 0 para procesarlos desde el principio.");
                        $importSinceId = 0;
                        $this->config->import_since_id = 0;
                        $this->config->save();
                    }
                }
            }

            if ($importSinceId > 0) {
                Tools::log()->info("Filtro de ID mínimo: {$importSinceId}");
            }

            // Obtener pedidos con límite para evitar timeout
            Tools::log()->info('[OrdersDownload::batch] Obteniendo pedidos desde la API (límite: 50' . ($importSinceId ? ", desde ID: {$importSinceId}" : '') . ')...');

            $orders = $this->connection->getOrders(
                50, // Límite de 50 pedidos por ejecución
                $importSinceId > 0 ? $importSinceId : null
            );

            if (empty($orders)) {
                Tools::log()->info("[OrdersDownload::batch] No hay pedidos nuevos para importar");
                return;
            }

            Tools::log()->info("[OrdersDownload::batch] Se obtuvieron " . count($orders) . " pedidos. Procesando...");

            foreach ($orders as $orderXml) {
                $orderId = (int)$orderXml->id;
                $orderRef = (string)$orderXml->reference;

                // IMPORTANTE: Usar la fecha del ÚLTIMO ESTADO, no la fecha de creación
                // Esto debe coincidir con la fecha que se usa al crear el albarán
                $orderDate = $this->getLastOrderStatusDate($orderXml, $orderId);
                if (!$orderDate) {
                    // Fallback a fecha de creación si no hay historial de estados
                    $orderDate = (string)$orderXml->date_add;
                }

                Tools::log()->info("[OrdersDownload::batch] >>> Pedido ID: {$orderId}, Ref: {$orderRef}, Fecha último estado: {$orderDate}");

                try {
                    // Filtro por fecha: Si está configurado, verificar fecha del ÚLTIMO ESTADO del pedido
                    if ($importSinceDate && $orderDate < $importSinceDate) {
                        Tools::log()->info("[OrdersDownload::batch] ⊘ OMITIDO POR FECHA: {$orderRef} (último estado: {$orderDate} < {$importSinceDate})");
                        PrestashopImportLog::logSkipped($orderId, $orderRef, "Fecha del último estado ({$orderDate}) anterior a la configurada ({$importSinceDate})", $origen);
                        $skipped++; // Contar como omitido
                        continue;
                    }

                    // Verificar si el pedido ya fue importado
                    if ($this->isOrderImported($orderRef)) {
                        Tools::log()->info("[OrdersDownload::batch] ⊘ YA IMPORTADO: {$orderRef}");
                        PrestashopImportLog::logSkipped($orderId, $orderRef, "Pedido ya importado anteriormente", $origen);
                        $skipped++;
                        continue;
                    }

                    // Importar el pedido
                    Tools::log()->info("[OrdersDownload::batch] Importando pedido {$orderRef}...");
                    $albaranData = $this->importOrder($orderXml);
                    if ($albaranData) {
                        $imported++;
                        Tools::log()->info("[OrdersDownload::batch] ✓ Pedido {$orderRef} importado correctamente");

                        // Registrar importación exitosa
                        PrestashopImportLog::logSuccess(
                            $orderId,
                            $orderRef,
                            $albaranData['idalbaran'],
                            $albaranData['codcliente'],
                            $albaranData['nombrecliente'],
                            $albaranData['total'],
                            $origen
                        );
                    } else {
                        Tools::log()->warning("[OrdersDownload::batch] Pedido {$orderRef} no se pudo importar (puede ya existir)");
                        PrestashopImportLog::logSkipped($orderId, $orderRef, "No se pudo importar (posiblemente ya existe)", $origen);
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $errorMsg = "Error importando pedido {$orderRef} (ID: {$orderId}): " . $e->getMessage();
                    Tools::log()->error($errorMsg);
                    $this->logError($errorMsg);

                    // Registrar error en log de importaciones
                    PrestashopImportLog::logError($orderId, $orderRef, $e->getMessage(), $origen);
                }
            }

            // Resumen de la importación
            Tools::log()->info('[OrdersDownload::batch] ========== RESUMEN DE IMPORTACIÓN ==========');
            Tools::log()->info('[OrdersDownload::batch] Pedidos importados: ' . $imported);
            Tools::log()->info('[OrdersDownload::batch] Pedidos omitidos (ya importados): ' . $skipped);
            Tools::log()->info('[OrdersDownload::batch] Errores: ' . $errors);
            Tools::log()->info('[OrdersDownload::batch] ===========================================');

            // IMPORTANTE: Si NO se importó ninguno, avanzar el puntero automáticamente SOLO si no fue por fecha
            // Esto permite atravesar pedidos ya importados, pero NO pedidos que no cumplen fecha
            if ($imported == 0 && count($orders) > 0) {
                // Verificar si TODOS fueron omitidos por fecha
                $allSkippedByDate = true;
                foreach ($orders as $orderXml) {
                    $orderDate = $this->getLastOrderStatusDate($orderXml, (int)$orderXml->id);
                    if (!$orderDate) {
                        $orderDate = (string)$orderXml->date_add;
                    }

                    // Si algún pedido NO fue omitido por fecha, podemos avanzar
                    if (empty($importSinceDate) || $orderDate >= $importSinceDate) {
                        $allSkippedByDate = false;
                        break;
                    }
                }

                // SOLO avanzar si NO todos fueron omitidos por fecha
                if (!$allSkippedByDate) {
                    $lastOrderXml = end($orders);
                    $lastOrderId = (int)$lastOrderXml->id;

                    $this->config->import_since_id = $lastOrderId;
                    $this->config->save();

                    Tools::log()->warning("[OrdersDownload::batch] ⚠ NO se importó ningún pedido en este lote (ya estaban importados).");
                    Tools::log()->warning("[OrdersDownload::batch] ⚠ Avanzando automáticamente import_since_id a {$lastOrderId}.");
                } else {
                    Tools::log()->warning("[OrdersDownload::batch] ⚠ NO se importó ningún pedido porque TODOS están fuera del rango de fecha.");
                    Tools::log()->warning("[OrdersDownload::batch] ⚠ NO se avanza el puntero. Ya hemos alcanzado el final de pedidos válidos.");
                }
            }

            if ($imported > 0) {
                Tools::log()->info("PrestaShop: Importados {$imported} pedidos como albaranes");
            }

            if ($skipped > 0) {
                Tools::log()->info("PrestaShop: {$skipped} pedidos ya estaban importados (omitidos)");
            }

            if ($imported == 0 && $skipped == 0 && $errors == 0) {
                Tools::log()->info("No hay pedidos nuevos para importar");
            }

            if ($errors > 0) {
                Tools::log()->warning("PrestaShop: {$errors} errores durante la importación");
            }

            // Guardar log de errores si hubo alguno
            if (!empty($this->importLog)) {
                $this->saveLog();
            }
        } catch (\Exception $e) {
            $errorMsg = 'Error en importación de pedidos: ' . $e->getMessage();
            Tools::log()->error($errorMsg);
            $this->logError($errorMsg);
            $this->saveLog();
        }
    }

    /**
     * Importa un pedido individual como albarán
     *
     * @return array|null Array con datos del albarán si se importó correctamente, null si falló
     */
    private function importOrder(\SimpleXMLElement $orderXml): ?array
    {
        $orderId = (int)$orderXml->id;
        $orderReference = (string)$orderXml->reference;

        // VALIDACIÓN: Verificar que el pedido tiene datos válidos
        if ($orderId <= 0) {
            Tools::log()->error("Pedido inválido: ID = 0. Posible error de conexión o datos corruptos.");
            throw new \Exception("Pedido con ID inválido (0). Verifica la conexión con PrestaShop.");
        }

        if (empty($orderReference)) {
            Tools::log()->warning("Pedido {$orderId} sin referencia, usando ID como referencia");
            $orderReference = "PS-{$orderId}";
        }

        // LOG: Inicio de importación
        Tools::log()->info("========================================");
        Tools::log()->info("IMPORTANDO PEDIDO: {$orderReference} (ID: {$orderId})");
        Tools::log()->info("========================================");

        // Verificar si ya existe un albarán con este número de pedido en numero2
        if ($this->albaranExists($orderReference)) {
            Tools::log()->debug("⊘ Albarán ya importado: {$orderReference}");
            return null;
        }

        // Obtener o crear cliente usando la dirección de facturación del pedido
        $customerId = (int)$orderXml->id_customer;
        $addressId = (int)$orderXml->id_address_invoice;

        // FALLBACK: Si no hay dirección de facturación, usar dirección de envío
        // Esto pasa en algunas versiones de PrestaShop cuando ambas direcciones son iguales
        if ($addressId <= 0) {
            $addressId = (int)$orderXml->id_address_delivery;
            Tools::log()->warning("Pedido {$orderId} sin dirección de facturación separada. Usando dirección de envío: {$addressId}");
        }

        // VALIDACIÓN: Verificar que el pedido tiene cliente y dirección
        if ($customerId <= 0 || $addressId <= 0) {
            Tools::log()->error("Pedido {$orderId} - Customer ID: {$customerId}, Invoice Address: {$orderXml->id_address_invoice}, Delivery Address: {$orderXml->id_address_delivery}");
            throw new \Exception("Pedido {$orderId} sin cliente ({$customerId}) o dirección ({$addressId}) válidos");
        }

        $cliente = $this->getOrCreateCliente($customerId, $addressId);
        if (!$cliente) {
            throw new \Exception("No se pudo obtener o crear el cliente para el pedido {$orderId}");
        }

        // Guardar datos del cliente para el log
        $codcliente = $cliente->codcliente;
        $nombrecliente = $cliente->nombre;

        // Crear albarán
        $albaran = new AlbaranCliente();
        $albaran->setSubject($cliente);

        // IMPORTANTE: Forzar que NO tenga recargo de equivalencia
        // Aunque el cliente tenga recargo, los pedidos de PrestaShop no lo usan
        $albaran->codregimeniva = '';

        $albaran->codalmacen = $this->config->codalmacen;
        $albaran->codserie = $this->config->codserie;

        // IMPORTANTE: Usar la fecha del ÚLTIMO estado del pedido, no la fecha de creación
        $lastStatusDate = $this->getLastOrderStatusDate($orderXml, $orderId);
        if ($lastStatusDate) {
            $albaran->fecha = date('d-m-Y', strtotime($lastStatusDate));
            $albaran->hora = date('H:i:s', strtotime($lastStatusDate));
            Tools::log()->info("Usando fecha del último estado: {$lastStatusDate}");
        } else {
            // Fallback: usar fecha de creación del pedido si no hay historial
            $albaran->fecha = date('d-m-Y', strtotime((string)$orderXml->date_add));
            $albaran->hora = date('H:i:s', strtotime((string)$orderXml->date_add));
            Tools::log()->info("Usando fecha de creación (sin historial): " . (string)$orderXml->date_add);
        }

        $albaran->numero2 = $orderReference; // Guardamos el número de pedido de PrestaShop en numero2
        $albaran->observaciones = "Importado de PrestaShop. ID: {$orderId}";

        // Asignar forma de pago según mapeo
        $paymentModule = (string)$orderXml->payment;
        if (!empty($paymentModule)) {
            $codpago = PrestashopPaymentMap::getCodPago($paymentModule);
            if ($codpago) {
                $albaran->codpago = $codpago;
                Tools::log()->info("✓ PAGO → PrestaShop: '{$paymentModule}' → FacturaScripts: '{$codpago}'");
            } else {
                Tools::log()->warning("⚠ PAGO → No hay mapeo para: '{$paymentModule}'");
            }
        } else {
            Tools::log()->warning("⚠ PAGO → Pedido sin método de pago");
        }

        // Obtener dirección de facturación (no de envío) y asignar TODOS los datos
        $addressId = (int)$orderXml->id_address_invoice;
        $address = $this->connection->getAddress($addressId);
        if ($address) {
            // El nombrecliente ya se ha asignado con setSubject($cliente)
            // Si el cliente es una empresa, el nombre ya es correcto
            // Si es particular, también es correcto
            // NO sobrescribir aquí - dejar que use los datos del cliente

            // Dirección completa
            $albaran->direccion = (string)$address->address1;
            if (!empty((string)$address->address2)) {
                $albaran->direccion .= "\n" . (string)$address->address2;
            }

            // Otros datos
            $albaran->codpostal = (string)$address->postcode;
            $albaran->ciudad = (string)$address->city;

            // Provincia: obtener el nombre desde el ID de estado
            $stateId = (int)$address->id_state;
            if ($stateId > 0) {
                $stateName = $this->connection->getStateName($stateId);
                if ($stateName) {
                    $albaran->provincia = $stateName;
                    Tools::log()->info("Provincia: {$stateName} (ID: {$stateId})");
                } else {
                    Tools::log()->warning("No se pudo obtener el nombre de la provincia con ID: {$stateId}");
                }
            }

            $albaran->apartado = (string)$address->other ?? '';

            // Teléfonos
            if (!empty((string)$address->phone)) {
                $albaran->telefono1 = (string)$address->phone;
            }
            if (!empty((string)$address->phone_mobile)) {
                $albaran->telefono2 = (string)$address->phone_mobile;
            }

            // País
            $albaran->codpais = $this->getCountryCode((int)$address->id_country);
        }

        if (!$albaran->save()) {
            throw new \Exception("No se pudo guardar el albarán para el pedido {$orderId}");
        }

        // Importar líneas de pedido
        // Como ahora getOrder() devuelve el pedido completo con display=full,
        // los productos ya están en $orderXml->associations->order_rows
        $products = [];
        if (isset($orderXml->associations->order_rows->order_row)) {
            foreach ($orderXml->associations->order_rows->order_row as $row) {
                $unitPriceTaxIncl = (float)$row->unit_price_tax_incl;
                $unitPriceTaxExcl = (float)$row->unit_price_tax_excl;

                // Calcular el tax_rate (porcentaje de IVA)
                $taxRate = 0;
                if ($unitPriceTaxExcl > 0) {
                    $taxRate = (($unitPriceTaxIncl / $unitPriceTaxExcl) - 1) * 100;
                    $taxRate = round($taxRate, 2);
                }

                $products[] = [
                    'product_id' => (int)$row->product_id,
                    'product_reference' => (string)$row->product_reference,
                    'product_name' => (string)$row->product_name,
                    'product_quantity' => (int)$row->product_quantity,
                    'unit_price_tax_incl' => $unitPriceTaxIncl,
                    'unit_price_tax_excl' => $unitPriceTaxExcl,
                    'tax_rate' => $taxRate,
                ];
            }
        }

        if (empty($products)) {
            Tools::log()->warning("Pedido {$orderId} sin productos. Verifica que associations->order_rows esté en el XML.");
        }

        foreach ($products as $product) {
            $this->addLineaAlbaran($albaran, $product);
        }

        // Añadir línea de gastos de envío si existe
        $totalShipping = (float)$orderXml->total_shipping;
        if ($totalShipping > 0) {
            $this->addShippingLine($albaran, $totalShipping);
        }

        // Añadir línea de empaquetado para regalo si existe
        $totalWrapping = (float)$orderXml->total_wrapping_tax_incl;
        if ($totalWrapping > 0) {
            $this->addGiftWrappingLine($albaran, $totalWrapping);
        }

        // Añadir línea de descuento/cupón si existe
        // IMPORTANTE: PrestaShop trae el descuento con IVA incluido (total_discounts_tax_incl)
        // Debemos calcular el importe sin IVA y asignar IVA 21%
        $totalDiscountWithTax = (float)$orderXml->total_discounts_tax_incl;
        if ($totalDiscountWithTax > 0) {
            // Intentar obtener el nombre del cupón/descuento
            $discountName = $this->getDiscountName($orderXml);
            $this->addDiscountLine($albaran, $totalDiscountWithTax, $discountName);
        }

        // Calcular y asignar totales manualmente
        $this->calculateTotals($albaran);

        Tools::log()->info("========================================");
        Tools::log()->info("✓ ALBARÁN CREADO: {$albaran->codigo}");
        Tools::log()->info("  Neto: {$albaran->neto}€ | IVA: {$albaran->totaliva}€ | TOTAL: {$albaran->total}€");
        Tools::log()->info("========================================");

        // Devolver datos del albarán para el log
        return [
            'idalbaran' => $albaran->idalbaran,
            'codcliente' => $codcliente,
            'nombrecliente' => $nombrecliente,
            'total' => $albaran->total
        ];
    }

    /**
     * Verifica si ya existe un albarán con este número de pedido
     */
    private function albaranExists(string $orderReference): bool
    {
        $albaran = new AlbaranCliente();
        $where = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('numero2', $orderReference)];
        return $albaran->loadFromCode('', $where);
    }

    /**
     * Verifica si un pedido ya fue importado (alias de albaranExists)
     */
    private function isOrderImported(string $orderReference): bool
    {
        return $this->albaranExists($orderReference);
    }

    /**
     * Obtiene o crea un cliente desde PrestaShop
     *
     * @param int $customerId ID del cliente en PrestaShop
     * @param int $addressId ID de la dirección de facturación del pedido
     */
    private function getOrCreateCliente(int $customerId, int $addressId): ?Cliente
    {
        $customerXml = $this->connection->getCustomer($customerId);
        if (!$customerXml) {
            return null;
        }

        $email = (string)$customerXml->email;

        // Obtener dirección de facturación del pedido para sacar el NIF/CIF real
        $addressXml = null;
        if ($addressId > 0) {
            $addressXml = $this->connection->getAddress($addressId);
        }

        // Extraer datos de la dirección
        $vat_number = '';
        $dni = '';
        $empresa = '';
        $isCompany = false;

        if ($addressXml) {
            // VAT number (CIF empresarial)
            $vat_number = trim((string)$addressXml->vat_number);

            // DNI personal (separado del VAT)
            $dni = trim((string)$addressXml->dni);

            // Nombre de empresa
            $empresa = trim((string)$addressXml->company);

            // CRÍTICO: Solo es empresa si tiene AMBOS campos REALMENTE llenos (no vacíos ni espacios)
            // Verificar con strlen para asegurar que no sean cadenas vacías
            $isCompany = (strlen($empresa) > 0) && (strlen($vat_number) > 0);

            Tools::log()->info("Empresa: '{$empresa}' | VAT: '{$vat_number}' | DNI: '{$dni}' | isCompany: " . ($isCompany ? 'SI' : 'NO'));
        }

        // Buscar cliente existente SOLO por CIF/NIF/DNI (ignorando formato)
        // Priorizar VAT, si no existe usar DNI
        $cifnif_busqueda = !empty($vat_number) ? $vat_number : $dni;
        if (!empty($cifnif_busqueda)) {
            $clienteExistente = $this->findClienteByCifNif($cifnif_busqueda);
            if ($clienteExistente) {
                return $clienteExistente;
            }
        }

        // Crear nuevo cliente - Usar datos de la dirección de facturación
        $cliente = new Cliente();
        $nombrePersona = '';
        if ($addressXml) {
            // Usar nombre de la dirección de facturación (invoice address)
            $nombrePersona = trim((string)$addressXml->firstname . ' ' . (string)$addressXml->lastname);
        }

        // Si no hay dirección, usar datos del customer como fallback
        if (empty($nombrePersona)) {
            $nombrePersona = trim((string)$customerXml->firstname . ' ' . (string)$customerXml->lastname);
        }

        // Si aún no tiene nombre, el pedido tiene datos inválidos - NO crear cliente falso
        if (empty($nombrePersona)) {
            Tools::log()->error("Cliente {$customerId} sin nombre válido. El pedido no tiene dirección de facturación correcta.");
            Tools::log()->error("Dirección ID: {$addressId} - Verifica que el pedido tenga invoice_address en PrestaShop");
            return null; // No crear clientes con datos inventados
        }

        if ($isCompany) {
            // Es una empresa (tiene empresa Y VAT)
            $cliente->nombre = $nombrePersona; // Nombre del contacto
            $cliente->razonsocial = $empresa; // Razón social de la empresa
            $cliente->personafisica = false; // NO es persona física
            $cliente->cifnif = $vat_number; // CIF de la empresa
            Tools::log()->info("Creando cliente empresa: {$empresa} (CIF: {$vat_number})");
        } else {
            // Es un particular (no tiene empresa O no tiene VAT)
            $cliente->nombre = $nombrePersona;
            $cliente->razonsocial = $nombrePersona;
            $cliente->personafisica = true; // Es persona física

            // Usar DNI/CIF real. Priorizar VAT, si no existe usar DNI, si no existe usar genérico
            if (!empty($vat_number)) {
                $cliente->cifnif = $vat_number;
                Tools::log()->info("Creando cliente particular: {$nombrePersona} (CIF: {$vat_number})");
            } elseif (!empty($dni)) {
                $cliente->cifnif = $dni;
                Tools::log()->info("Creando cliente particular: {$nombrePersona} (DNI: {$dni})");
            } else {
                // DNI genérico para consumidor final (común en España)
                $cliente->cifnif = '999999999';
                Tools::log()->warning("Cliente {$customerId} sin DNI/CIF en PrestaShop. Usando DNI genérico.");
            }
        }

        $cliente->email = $email;

        // Dejar que FacturaScripts genere el codcliente automáticamente
        // En FacturaScripts 2025 se genera automáticamente si se deja vacío

        $cliente->observaciones = "Importado de PrestaShop. ID: {$customerId}";

        if ($cliente->save()) {
            Tools::log()->info("Cliente creado: {$cliente->codcliente} - {$cliente->nombre}");
            return $cliente;
        }

        return null;
    }

    /**
     * Busca un cliente por CIF/NIF/DNI ignorando mayúsculas y posición de letra
     */
    private function findClienteByCifNif(string $cifnif): ?Cliente
    {
        $cifNormalizado = $this->normalizeCifNif($cifnif);

        // Buscar todos los clientes y comparar normalizados
        $cliente = new Cliente();
        $allClientes = $cliente->all([], [], 0, 0);

        foreach ($allClientes as $cli) {
            if (!empty($cli->cifnif)) {
                $cliNormalizado = $this->normalizeCifNif($cli->cifnif);
                if ($cifNormalizado === $cliNormalizado) {
                    Tools::log()->info("Cliente encontrado por CIF/NIF: {$cli->cifnif} (original: {$cifnif})");
                    return $cli;
                }
            }
        }

        return null;
    }

    /**
     * Normaliza un CIF/NIF/DNI para comparación flexible
     * Ejemplo: "B12345678" == "b12345678" == "12345678B" == "12345678b"
     */
    private function normalizeCifNif(string $cifnif): string
    {
        // Eliminar espacios y guiones
        $cifnif = str_replace([' ', '-'], '', $cifnif);

        // Convertir a mayúsculas
        $cifnif = strtoupper($cifnif);

        // Extraer números y letras
        preg_match_all('/[A-Z0-9]/', $cifnif, $matches);
        $chars = $matches[0] ?? [];

        // Separar números y letras
        $numeros = [];
        $letras = [];

        foreach ($chars as $char) {
            if (is_numeric($char)) {
                $numeros[] = $char;
            } else {
                $letras[] = $char;
            }
        }

        // Formato normalizado: números + letras (todo en mayúsculas)
        return implode('', $numeros) . implode('', $letras);
    }

    /**
     * Genera un código de cliente único válido (solo letras y números, sin espacios)
     */
    private function generateCodCliente(string $nombre, int $customerId): string
    {
        // Limpiar el nombre: solo letras y números
        $base = preg_replace('/[^A-Za-z0-9]/', '', $nombre);
        $base = strtoupper(substr($base, 0, 6));

        // Si está vacío, usar PS
        if (empty($base)) {
            $base = 'PS';
        }

        $codigo = $base . $customerId;
        $counter = 1;

        $cliente = new Cliente();
        while ($cliente->loadFromCode($codigo)) {
            $codigo = $base . $customerId . $counter;
            $counter++;
            if ($counter > 100) {
                // Fallback: usar solo el ID
                $codigo = 'PS' . $customerId . rand(1000, 9999);
                break;
            }
        }

        return substr($codigo, 0, 10); // Max 10 caracteres
    }

    /**
     * Añade una línea al albarán
     */
    private function addLineaAlbaran(AlbaranCliente $albaran, array $product): void
    {
        $linea = new LineaAlbaranCliente();
        $linea->idalbaran = $albaran->idalbaran;
        $linea->cantidad = $product['product_quantity'];
        $linea->pvpunitario = $product['unit_price_tax_excl'];
        $linea->descripcion = $product['product_name'];

        $referencia = $product['product_reference'] ?? 'PS-' . $product['product_id'];

        // Buscar producto por referencia
        if (!empty($referencia)) {
            $variante = new Variante();
            $where = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('referencia', $referencia)];

            if ($variante->loadFromCode('', $where)) {
                // Producto existe
                $linea->idproducto = $variante->idproducto;
                $linea->referencia = $variante->referencia;
                // Mantener descripción del producto de FacturaScripts si existe
                $producto = new Producto();
                if ($producto->loadFromCode($variante->idproducto)) {
                    $linea->descripcion = $producto->descripcion;
                }
            } else {
                // Producto NO existe - Crear automáticamente
                $nuevoProducto = $this->crearProducto($product, $referencia);
                if ($nuevoProducto) {
                    $linea->idproducto = $nuevoProducto->idproducto;
                    $linea->referencia = $referencia;
                    $linea->descripcion = $nuevoProducto->descripcion;
                }else {
                    // Si falla la creación, crear línea sin producto
                    $linea->referencia = $referencia;
                }
            }
        }

        // CRÍTICO: Asignar codimpuesto DESPUÉS de asignar el producto
        // Esto sobrescribe el codimpuesto del producto con el correcto según PrestaShop
        $taxRate = $product['tax_rate'] ?? 21;
        $codimpuesto = PrestashopTaxMap::getCodImpuesto($taxRate);

        if ($codimpuesto) {
            // Mapeo encontrado: usar el codimpuesto mapeado
            $linea->codimpuesto = $codimpuesto;
            $linea->iva = $taxRate;
        } else {
            // Sin mapeo: solo asignar IVA y advertir
            $linea->iva = $taxRate;
            Tools::log()->warning("⚠ IVA {$taxRate}% sin mapear para producto {$referencia}. Configura el mapeo de IVA en Prestashop → Mapeo de Tipos de IVA");
        }

        // IMPORTANTE: Calcular pvptotal para que se muestre correctamente en la vista
        $linea->pvptotal = round(
            $linea->pvpunitario * $linea->cantidad * (1 - $linea->dtopor / 100) * (1 - $linea->dtopor2 / 100),
            2
        );

        // NO hay recargo de equivalencia
        $linea->recargo = 0;

        $linea->save();

        // LOG DETALLADO para debug
        $precioConIva = round($linea->pvpunitario * (1 + $linea->iva / 100), 2);
        Tools::log()->info("✓ PRODUCTO → {$referencia} | Cant: {$linea->cantidad} | Sin IVA: {$linea->pvpunitario}€ | IVA: {$linea->iva}% | Con IVA: {$precioConIva}€");
    }

    /**
     * Crea un producto en FacturaScripts desde datos de PrestaShop
     */
    private function crearProducto(array $product, string $referencia): ?Producto
    {
        try {
            $producto = new Producto();
            $producto->descripcion = $product['product_name'];
            $producto->precio = (float)$product['unit_price_tax_excl'];
            $producto->nostock = true; // Se puede vender sin stock
            $producto->ventasinstock = true; // Permitir ventas sin stock
            $producto->bloqueado = false;

            // Obtener IVA desde mapeo
            if (isset($product['tax_rate'])) {
                $taxRate = (float)$product['tax_rate'];
                $codimpuesto = PrestashopTaxMap::getCodImpuesto($taxRate);
                if ($codimpuesto) {
                    $producto->codimpuesto = $codimpuesto;
                }
            }

            // Si no hay mapeo, usar IVA por defecto (21%)
            if (empty($producto->codimpuesto)) {
                $producto->codimpuesto = 'IVA21'; // Ajustar según tu configuración
            }

            if ($producto->save()) {
                // Actualizar la variante principal con la referencia
                $variante = $producto->getVariants()[0] ?? null;
                if ($variante) {
                    $variante->referencia = $referencia;
                    $variante->save();
                }

                Tools::log()->info("Producto creado automáticamente: {$referencia} - {$producto->descripcion}");
                return $producto;
            }

            return null;
        } catch (\Exception $e) {
            Tools::log()->error("Error creando producto {$referencia}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtiene el código de país de FacturaScripts desde el ID de PrestaShop
     */
    private function getCountryCode(int $countryId): string
    {
        // Mapeo básico de países comunes
        $countryMap = [
            6 => 'ESP',  // España
            8 => 'FRA',  // Francia
            17 => 'DEU', // Alemania
            110 => 'ITA', // Italia
            13 => 'GBR', // Reino Unido
            21 => 'USA', // Estados Unidos
        ];

        return $countryMap[$countryId] ?? 'ESP';
    }

    /**
     * Añade línea de gastos de envío al albarán
     */
    private function addShippingLine(AlbaranCliente $albaran, float $shippingCostWithTax): void
    {
        // Buscar el producto de envío
        $variante = new Variante();
        $where = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('referencia', 'ENVIO-PRESTASHOP')];

        if (!$variante->loadFromCode('', $where)) {
            Tools::log()->warning("Producto 'Gastos de envío' no encontrado. Créalo con referencia ENVIO-PRESTASHOP");
            return;
        }

        // IMPORTANTE: total_shipping viene CON IVA, hay que quitárselo
        $ivaTransporte = 21; // IVA del transporte
        $shippingCostWithoutTax = $shippingCostWithTax / (1 + $ivaTransporte / 100);

        $linea = new LineaAlbaranCliente();
        $linea->idalbaran = $albaran->idalbaran;
        $linea->idproducto = $variante->idproducto;
        $linea->referencia = $variante->referencia;
        $linea->descripcion = 'Gastos de envío';
        $linea->cantidad = 1;
        $linea->pvpunitario = round($shippingCostWithoutTax, 2); // Precio SIN IVA

        // Asignar codimpuesto correcto para el transporte
        $codimpuesto = PrestashopTaxMap::getCodImpuesto($ivaTransporte);
        if ($codimpuesto) {
            $linea->codimpuesto = $codimpuesto;
            $linea->iva = $ivaTransporte;
        } else {
            // Fallback: solo IVA
            $linea->iva = $ivaTransporte;
            Tools::log()->warning("⚠ IVA {$ivaTransporte}% sin mapear para transporte. Configura el mapeo de IVA.");
        }

        // Calcular pvptotal
        $linea->pvptotal = round($linea->pvpunitario * $linea->cantidad, 2);

        // NO hay recargo de equivalencia
        $linea->recargo = 0;

        $linea->save();

        Tools::log()->info("✓ ENVÍO → Con IVA: {$shippingCostWithTax}€ | Sin IVA: {$linea->pvpunitario}€ | IVA: {$ivaTransporte}%");
    }

    /**
     * Añade línea de empaquetado para regalo al albarán
     */
    private function addGiftWrappingLine(AlbaranCliente $albaran, float $wrappingCostWithTax): void
    {
        // Buscar el producto de empaquetado para regalo
        $variante = new Variante();
        $where = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('referencia', 'REGALO-PRESTASHOP')];

        if (!$variante->loadFromCode('', $where)) {
            Tools::log()->warning("Producto 'Empaquetado para regalo' no encontrado. Créalo con referencia REGALO-PRESTASHOP");
            return;
        }

        // IMPORTANTE: total_wrapping viene CON IVA, hay que quitárselo
        $ivaRegalo = 21; // IVA del empaquetado para regalo
        $wrappingCostWithoutTax = $wrappingCostWithTax / (1 + $ivaRegalo / 100);

        $linea = new LineaAlbaranCliente();
        $linea->idalbaran = $albaran->idalbaran;
        $linea->idproducto = $variante->idproducto;
        $linea->referencia = $variante->referencia;
        $linea->descripcion = 'Empaquetado para regalo';
        $linea->cantidad = 1;
        $linea->pvpunitario = round($wrappingCostWithoutTax, 2); // Precio SIN IVA

        // Asignar codimpuesto correcto para el empaquetado
        $codimpuesto = PrestashopTaxMap::getCodImpuesto($ivaRegalo);
        if ($codimpuesto) {
            $linea->codimpuesto = $codimpuesto;
            $linea->iva = $ivaRegalo;
        } else {
            // Fallback: solo IVA
            $linea->iva = $ivaRegalo;
            Tools::log()->warning("⚠ IVA {$ivaRegalo}% sin mapear para empaquetado. Configura el mapeo de IVA.");
        }

        // Calcular pvptotal
        $linea->pvptotal = round($linea->pvpunitario * $linea->cantidad, 2);

        // NO hay recargo de equivalencia
        $linea->recargo = 0;

        $linea->save();

        Tools::log()->info("✓ REGALO → Con IVA: {$wrappingCostWithTax}€ | Sin IVA: {$linea->pvpunitario}€ | IVA: {$ivaRegalo}%");
    }

    /**
     * Añade línea de descuento/cupón al albarán (línea negativa con IVA 21%)
     *
     * @param AlbaranCliente $albaran Albarán al que añadir el descuento
     * @param float $discountWithTax Importe del descuento CON IVA incluido desde PrestaShop
     * @param string $discountName Nombre del cupón/descuento desde PrestaShop
     */
    private function addDiscountLine(AlbaranCliente $albaran, float $discountWithTax, string $discountName = ''): void
    {
        // PrestaShop trae el descuento con IVA incluido (total_discounts_tax_incl)
        // Calcular el importe sin IVA para la línea (asumiendo IVA 21%)
        $ivaDescuento = 21;
        $discountWithoutTax = $discountWithTax / (1 + $ivaDescuento / 100);

        // Crear línea negativa (descuento) con IVA 21%
        $linea = new LineaAlbaranCliente();
        $linea->idalbaran = $albaran->idalbaran;
        $linea->referencia = 'DCTO-PS';
        $linea->descripcion = !empty($discountName) ? $discountName : 'Descuento / Cupón';
        $linea->cantidad = 1;
        $linea->pvpunitario = -round($discountWithoutTax, 2); // Precio NEGATIVO sin IVA

        // Asignar IVA 21%
        $codimpuesto = PrestashopTaxMap::getCodImpuesto($ivaDescuento);
        if ($codimpuesto) {
            $linea->codimpuesto = $codimpuesto;
            $linea->iva = $ivaDescuento;
        } else {
            // Fallback: solo IVA
            $linea->iva = $ivaDescuento;
            Tools::log()->warning("⚠ IVA {$ivaDescuento}% sin mapear para descuento. Configura el mapeo de IVA.");
        }

        // Calcular pvptotal
        $linea->pvptotal = round($linea->pvpunitario * $linea->cantidad, 2);

        // NO hay recargo de equivalencia
        $linea->recargo = 0;

        $linea->save();

        Tools::log()->info("✓ DESCUENTO → '{$linea->descripcion}': Con IVA: -{$discountWithTax}€ | Sin IVA: {$linea->pvpunitario}€ | IVA: {$ivaDescuento}%");
    }

    /**
     * Obtiene el nombre del descuento/cupón desde PrestaShop
     */
    private function getDiscountName(\SimpleXMLElement $orderXml): string
    {
        // PrestaShop puede tener información de cupones en associations
        if (isset($orderXml->associations->order_cart_rules->order_cart_rule)) {
            $cartRules = $orderXml->associations->order_cart_rules->order_cart_rule;

            // Si hay un solo cupón
            if (isset($cartRules->name)) {
                $nombreCupon = (string)$cartRules->name;
                Tools::log()->info("Cupón encontrado: {$nombreCupon}");
                return 'Dcto: ' . $nombreCupon;
            }

            // Si hay múltiples cupones
            $names = [];
            foreach ($cartRules as $rule) {
                if (isset($rule->name)) {
                    $names[] = (string)$rule->name;
                }
            }

            if (!empty($names)) {
                $nombresCupones = implode(', ', $names);
                Tools::log()->info("Cupones encontrados: {$nombresCupones}");
                return 'Dcto: ' . $nombresCupones;
            }
        }

        Tools::log()->info("No se encontró nombre de cupón en PrestaShop - usando genérico");
        return 'Descuento / Cupón';
    }

    /**
     * Calcula y asigna los totales del albarán
     */
    private function calculateTotals(AlbaranCliente $albaran): void
    {
        // Obtener todas las líneas del albarán
        $lineas = $albaran->getLines();

        $neto = 0;
        $totalIva = 0;

        foreach ($lineas as $linea) {
            $lineaNeto = $linea->pvpunitario * $linea->cantidad * (1 - $linea->dtopor / 100) * (1 - $linea->dtopor2 / 100);
            $lineaIva = $lineaNeto * ($linea->iva / 100);

            $neto += $lineaNeto;
            $totalIva += $lineaIva;
        }

        // Asignar totales
        $albaran->neto = round($neto, 2);
        $albaran->totaliva = round($totalIva, 2);
        $albaran->total = round($neto + $totalIva, 2);

        // IMPORTANTE: NO hay recargo de equivalencia
        $albaran->totalrecargo = 0;

        // Guardar con totales calculados
        $albaran->save();

        Tools::log()->debug("Totales calculados - Neto: {$albaran->neto}, IVA: {$albaran->totaliva}, Total: {$albaran->total}");
    }

    /**
     * Registra un error en el log de importación
     */
    private function logError(string $message): void
    {
        $this->importLog[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => $message
        ];
    }

    /**
     * Guarda el log de errores en un archivo
     */
    private function saveLog(): void
    {
        $logDir = \FS_FOLDER . '/MyFiles/Logs';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/prestashop_import_errors.log';
        $content = '';

        foreach ($this->importLog as $entry) {
            $content .= "[{$entry['timestamp']}] {$entry['message']}\n";
        }

        file_put_contents($logFile, $content, FILE_APPEND);
    }

    /**
     * Obtiene las últimas líneas del log de errores
     */
    public static function getRecentLogs(int $lines = 100): array
    {
        $logFile = \FS_FOLDER . '/MyFiles/Logs/prestashop_import_errors.log';

        if (!file_exists($logFile)) {
            return [];
        }

        $content = file_get_contents($logFile);
        $logLines = explode("\n", trim($content));

        // Obtener las últimas N líneas
        $recentLines = array_slice($logLines, -$lines);

        return array_reverse($recentLines);
    }

    /**
     * Obtiene la fecha del último estado del pedido
     */
    private function getLastOrderStatusDate(\SimpleXMLElement $orderXml, int $orderId): ?string
    {
        try {
            // Intentar 1: order_state_histories (plural) en associations del XML
            if (isset($orderXml->associations->order_state_histories->order_state_history)) {
                $histories = $orderXml->associations->order_state_histories->order_state_history;

                $historyArray = [];
                foreach ($histories as $history) {
                    $historyArray[] = [
                        'date' => (string)$history->date_add,
                        'id_order_state' => (int)$history->id_order_state
                    ];
                }

                if (!empty($historyArray)) {
                    usort($historyArray, function($a, $b) {
                        return strtotime($b['date']) - strtotime($a['date']);
                    });

                    return $historyArray[0]['date'];
                }
            }

            // Intentar 2: order_history (singular) en associations del XML
            if (isset($orderXml->associations->order_history)) {
                $histories = $orderXml->associations->order_history;

                $historyArray = [];
                foreach ($histories as $history) {
                    $historyArray[] = [
                        'date' => (string)$history->date_add,
                        'id_order_state' => (int)$history->id_order_state
                    ];
                }

                if (!empty($historyArray)) {
                    usort($historyArray, function($a, $b) {
                        return strtotime($b['date']) - strtotime($a['date']);
                    });

                    return $historyArray[0]['date'];
                }
            }

            // Intentar 3: Desde API order_histories
            $history = $this->connection->getOrderHistory($orderId);

            if (!empty($history)) {
                $lastStatus = $history[0];
                $dateAdd = (string)$lastStatus->date_add;

                if (!empty($dateAdd)) {
                    return $dateAdd;
                }
            }

            return null;
        } catch (\Exception $e) {
            Tools::log()->error("Error obteniendo fecha del último estado: " . $e->getMessage());
            return null;
        }
    }
}
