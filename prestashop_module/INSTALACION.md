# 🚀 INSTALACIÓN DEL MÓDULO PRESTASHOP

## ⚠️ IMPORTANTE: El módulo NO está instalado automáticamente

Los archivos del módulo están en esta carpeta pero **NECESITAS INSTALARLO** manualmente en tu tienda PrestaShop.

---

## 📦 Paso 1: Subir el módulo a PrestaShop

### Opción A: Subir por FTP (Recomendado)

1. Conecta por FTP/SFTP a tu servidor PrestaShop
2. Navega a la carpeta `/modules/`
3. Copia la carpeta `fsfacturascripts` completa a `/modules/`
4. La estructura debe quedar así:
   ```
   /modules/fsfacturascripts/
       ├── fsfacturascripts.php
       ├── config.xml
       └── views/
           └── templates/
               └── hook/
                   └── displayOrderDetail.tpl
   ```

### Opción B: Subir desde el backoffice

1. Comprimir la carpeta `fsfacturascripts` en un ZIP
2. En PrestaShop admin: **Módulos > Module Manager**
3. Click en **"Subir un módulo"**
4. Arrastra el archivo ZIP
5. Espera a que se suba

---

## ⚙️ Paso 2: Instalar el módulo

1. Ve a **Módulos > Module Manager** en tu backoffice PrestaShop
2. Busca **"FacturaScripts Integration"**
3. Haz clic en **Instalar**
4. Confirma la instalación

El módulo creará automáticamente la tabla `ps_fs_facturascripts` en la base de datos.

---

## 🔧 Paso 3: Configurar el módulo

1. Después de instalar, haz clic en **Configurar**
2. Completa los campos:
   - **URL de FacturaScripts**: `https://tudominio.com` (tu URL completa)
   - **Token Webhook**: Ve a FacturaScripts > Configuración PrestaShop > Webhooks > Copia el token
   - **Activar Webhooks**: Marca como **Sí**
3. Haz clic en **Guardar**

---

## ✅ Paso 4: Probar que funciona

### Probar desde FacturaScripts:

1. Ve a **Configuración PrestaShop > Webhooks**
2. Busca la sección **"Probar Webhook"**
3. Introduce el ID de un pedido existente en PrestaShop (ej: 123)
4. Haz clic en **"Enviar Prueba"**
5. Verás el webhook en el historial abajo

### Probar desde PrestaShop (creando pedido):

1. Crea un pedido de prueba en tu tienda PrestaShop
2. El módulo enviará automáticamente un webhook a FacturaScripts
3. Ve a **FacturaScripts > Dashboard PrestaShop**
4. Deberías ver el pedido importado con origen **"webhook"**

---

## 🔍 Ver botón de descarga de facturas

Para que aparezca el botón de descarga:

1. El pedido debe estar **importado en FacturaScripts** (aparece en Dashboard)
2. El albarán debe tener una **factura generada** en FacturaScripts
3. Entonces en PrestaShop:
   - **Backoffice**: Pedidos > Ver pedido → Panel lateral "FacturaScripts" con botón
   - **Front**: Mi cuenta > Mis pedidos > Ver detalle → Caja con botón de descarga

---

## 🐛 Solución de problemas

### No aparece el módulo en PrestaShop
- Verifica que la carpeta esté en `/modules/fsfacturascripts/`
- Verifica permisos (chmod 755 en carpetas, 644 en archivos)
- Limpia caché de PrestaShop

### Los webhooks no se envían
- Verifica que "Activar Webhooks" esté marcado en configuración del módulo
- Verifica que la URL y token sean correctos
- Revisa los logs de PrestaShop en: **Parámetros Avanzados > Logs**

### No aparece el botón de descarga
- Verifica que el pedido esté importado en FacturaScripts
- Verifica que el albarán tenga factura asociada
- Verifica que el webhook haya funcionado (historial en FacturaScripts)

### Error "Tabla ps_fs_facturascripts no existe"
- Desinstala y reinstala el módulo
- Verifica permisos de base de datos

---

## 📍 Ubicaciones de archivos

- **Módulo PrestaShop**: `prestashop_module/fsfacturascripts/`
- **README completo**: `prestashop_module/README.md`
- **Controlador webhook FacturaScripts**: `prestashop/Controller/WebhookPrestashop.php`
- **Controlador descarga PDF**: `prestashop/Controller/DownloadInvoicePrestashop.php`

---

## 💡 Consejos

1. **Prueba siempre con el botón de prueba** antes de hacer pedidos reales
2. **Revisa el historial de webhooks** en FacturaScripts para depurar
3. **Genera las facturas en FacturaScripts** para que aparezca el botón de descarga
4. **El cron sigue funcionando** como respaldo si los webhooks fallan

---

¿Necesitas ayuda? Revisa los logs en:
- PrestaShop: **Parámetros Avanzados > Logs**
- FacturaScripts: **Dashboard PrestaShop** (pestañas de errores)
