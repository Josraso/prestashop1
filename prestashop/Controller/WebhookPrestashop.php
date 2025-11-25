<?php

namespace FacturaScripts\Plugins\Prestashop\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopConfig;
use FacturaScripts\Plugins\Prestashop\Model\PrestashopWebhookLog;
use FacturaScripts\Plugins\Prestashop\Lib\PrestashopConnection;
use FacturaScripts\Plugins\Prestashop\Lib\Actions\OrdersDownload;

/**
 * Endpoint público para recibir webhooks de PrestaShop
 * URL: https://tudominio.com/WebhookPrestashop?token=XXXXX
 */
class WebhookPrestashop extends Controller
{
    /**
     * Permite acceso público sin autenticación
     */
    public function publicCore(&$response): void
    {
        parent::publicCore($response);

        // Solo aceptar POST
        if ($this->request->getMethod() !== 'POST') {
            $this->sendResponse(405, ['error' => 'Método no permitido. Solo POST.']);
            return;
        }

        // Obtener IP del cliente
        $ip = $this->request->getClientIp();

        // Obtener payload
        $rawPayload = file_get_contents('php://input');
        $payload = json_decode($rawPayload, true);

        if (!$payload) {
            $payload = $_POST; // Fallback a POST data si no es JSON
        }

        // Log inicial del webhook recibido
        Tools::log()->info('========================================');
        Tools::log()->info('WEBHOOK PRESTASHOP RECIBIDO');
        Tools::log()->info("IP: {$ip}");
        Tools::log()->info("Payload: " . substr($rawPayload, 0, 200));
        Tools::log()->info('========================================');

        // Validar token
        $token = $this->request->query->get('token', '');
        $config = PrestashopConfig::getActive();

        if (!$config) {
            $webhookLog = PrestashopWebhookLog::logWebhook($ip, 'POST', $payload, false);
            $webhookLog->markProcessed(false, 'Configuración de PrestaShop no encontrada');
            $this->sendResponse(500, ['error' => 'Configuración no encontrada']);
            return;
        }

        // Verificar que los webhooks estén habilitados
        if (!$config->webhook_enabled) {
            $webhookLog = PrestashopWebhookLog::logWebhook($ip, 'POST', $payload, false);
            $webhookLog->markProcessed(false, 'Webhooks deshabilitados en configuración');
            $this->sendResponse(403, ['error' => 'Webhooks deshabilitados']);
            return;
        }

        // Validar token
        $tokenValido = ($token === $config->webhook_token);
        if (!$tokenValido) {
            Tools::log()->warning("Token inválido. Recibido: {$token}");
            $webhookLog = PrestashopWebhookLog::logWebhook($ip, 'POST', $payload, false);
            $webhookLog->markProcessed(false, 'Token inválido');
            $this->sendResponse(403, ['error' => 'Token inválido']);
            return;
        }

        // Token válido, extraer order_id del payload
        $orderId = $this->extractOrderId($payload);

        if (!$orderId) {
            Tools::log()->error('No se pudo extraer order_id del payload');
            $webhookLog = PrestashopWebhookLog::logWebhook($ip, 'POST', $payload, true);
            $webhookLog->markProcessed(false, 'No se encontró order_id en el payload');
            $this->sendResponse(400, ['error' => 'order_id no encontrado en payload']);
            return;
        }

        // Registrar webhook válido
        $webhookLog = PrestashopWebhookLog::logWebhook($ip, 'POST', $payload, true, $orderId);

        // Procesar el pedido inmediatamente
        try {
            Tools::log()->info("Procesando pedido ID: {$orderId} desde webhook");

            $connection = new PrestashopConnection($config);
            $orderXml = $connection->getOrder($orderId);

            if (!$orderXml) {
                throw new \Exception("No se pudo obtener el pedido {$orderId} de PrestaShop");
            }

            // Importar el pedido
            $importer = new OrdersDownload();
            $reflection = new \ReflectionClass($importer);
            $method = $reflection->getMethod('importOrder');
            $method->setAccessible(true);

            $result = $method->invoke($importer, $orderXml);

            if ($result) {
                $webhookLog->markProcessed(true, "Pedido importado correctamente");
                Tools::log()->info("✓ Webhook procesado exitosamente: Pedido {$orderId} importado");
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Pedido importado correctamente',
                    'order_id' => $orderId
                ]);
            } else {
                $webhookLog->markProcessed(false, "El pedido ya estaba importado o falló la importación");
                Tools::log()->warning("Webhook procesado pero pedido no importado (ya existe): {$orderId}");
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Pedido ya importado anteriormente',
                    'order_id' => $orderId
                ]);
            }

        } catch (\Exception $e) {
            $webhookLog->markProcessed(false, $e->getMessage());
            Tools::log()->error("Error procesando webhook: " . $e->getMessage());
            $this->sendResponse(500, [
                'error' => 'Error procesando pedido: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Extrae el order_id del payload del webhook
     */
    private function extractOrderId(array $payload): ?int
    {
        // Intentar diferentes formatos comunes de webhooks de PrestaShop
        if (isset($payload['id_order'])) {
            return (int)$payload['id_order'];
        }

        if (isset($payload['order_id'])) {
            return (int)$payload['order_id'];
        }

        if (isset($payload['orderId'])) {
            return (int)$payload['orderId'];
        }

        if (isset($payload['id'])) {
            return (int)$payload['id'];
        }

        // Si el payload es directamente el ID
        if (is_numeric($payload)) {
            return (int)$payload;
        }

        return null;
    }

    /**
     * Envía respuesta JSON y termina la ejecución
     */
    private function sendResponse(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        die();
    }

    /**
     * Información de la página (no se usa en webhooks pero requerido por FacturaScripts)
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['title'] = 'Webhook PrestaShop';
        $data['icon'] = 'fas fa-webhook';
        $data['showonmenu'] = false;
        return $data;
    }
}
