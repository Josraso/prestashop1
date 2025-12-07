<?php
/**
 * Script para limpiar la caché de PHP/OPcache
 * Ejecutar desde línea de comandos o navegador
 */

echo "=== LIMPIEZA DE CACHÉ DE PHP ===\n\n";

// Limpiar OPcache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✓ OPcache limpiada\n";
} else {
    echo "⚠ OPcache no está habilitada\n";
}

// Limpiar APCu
if (function_exists('apcu_clear_cache')) {
    apcu_clear_cache();
    echo "✓ APCu limpiada\n";
} else {
    echo "⚠ APCu no está habilitada\n";
}

// Información de versión
echo "\n=== INFORMACIÓN ===\n";
echo "Versión de plugin: 4.0\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "Archivo ProductsDownload.php modificado: " . date('Y-m-d H:i:s', filemtime(__DIR__ . '/Lib/Actions/ProductsDownload.php')) . "\n";

echo "\n✓ PROCESO COMPLETADO\n";
echo "\nAhora ve a FacturaScripts y:\n";
echo "1. Panel de Control → Plugins\n";
echo "2. Desactiva 'Prestashop'\n";
echo "3. Activa 'Prestashop' de nuevo\n";
echo "4. Verifica que dice 'Versión 4.0'\n";
echo "5. Intenta importar un producto\n";
echo "6. Busca en los logs: '=== IMPORTANDO PRODUCTO - VERSIÓN 4.0 CARGADA ==='\n";
