<?php
/**
 * CRON ejecutable desde línea de comandos o wget
 *
 * Uso desde crontab del sistema:
 * */10 * * * * php /ruta/a/prestashop/modules/fsfacturascripts/cron.php
 *
 * O usando wget:
 * */10 * * * * wget -q -O- "https://tutienda.com/modules/fsfacturascripts/cron.php?token=TU_TOKEN_SECRETO" > /dev/null 2>&1
 */

// Seguridad: Verificar token si se ejecuta desde web
if (php_sapi_name() !== 'cli') {
    $token = isset($_GET['token']) ? $_GET['token'] : '';
    $expected_token = 'facturascripts_cron_2025'; // Cambiar esto por un token seguro

    if ($token !== $expected_token) {
        http_response_code(403);
        die('Token inválido');
    }
}

// Cargar PrestaShop
$prestashop_path = dirname(__FILE__) . '/../..';
require_once($prestashop_path . '/config/config.inc.php');

// Cargar el módulo
$module = Module::getInstanceByName('fsfacturascripts');

if (!$module || !$module->active) {
    die('Módulo no encontrado o inactivo');
}

// Ejecutar sincronización
echo "[" . date('Y-m-d H:i:s') . "] Iniciando sincronización CRON...\n";

$result = $module->hookActionCronJob();

if (is_array($result) && isset($result['error'])) {
    echo "[ERROR] " . $result['error'] . "\n";
    exit(1);
} else {
    echo "[OK] Sincronización completada\n";
    exit(0);
}
