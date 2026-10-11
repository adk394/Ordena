# Ordena

Aplicación PHP ligera para limpiar datos administrativos. Admite archivos CSV y libros Excel `.xlsx` de hasta 5 MB y 10.000 filas. No necesita Composer ni bases de datos.

## Ejecutar en local

Requiere PHP 8.1 o posterior con `zlib`, `SimpleXML` e `iconv`. Para conectar con OpenAI desde una instalación PHP propia, también se necesita `openssl` y `allow_url_fopen` habilitado.

```powershell
php -S localhost:8000
```

Abre <http://localhost:8000>. Para ejecutar la prueba del limpiador:

```powershell
php tests/DataCleanerTest.php
php tests/AiCategorizerTest.php
```

En una instalación pública, sirve la aplicación detrás de HTTPS y configura PHP para limitar las subidas a 5 MB o más, sin superar el límite que aplica la aplicación.

## Publicar en Render

El proyecto incluye un `Dockerfile` y un blueprint `render.yaml`:

1. Sube el proyecto a un repositorio privado de GitHub.
2. En Render, crea un **Blueprint** y conecta ese repositorio.
3. Render construye y publica el servicio. La dirección pública aparecerá en el panel del servicio.
4. Si quieres activar la IA, añade `OPENAI_API_KEY` en **Environment** en Render. La clave no se guarda en el repositorio ni se solicita durante el despliegue.

La limpieza local funciona aunque no configures una clave. El servicio público no lleva inicio de sesión: no subas información confidencial ni añadas una clave de IA hasta que quieras asumir el coste y uso de ese servicio. Mantén una sola instancia, porque la sesión que permite descargar el resultado se guarda temporalmente en el servidor. En el plan gratuito puede tardar unos segundos en despertar y las sesiones se pierden si el servicio se reinicia.

Para ejecutar la misma imagen con Docker en local (sin HTTPS, por eso se desactiva la cookie segura):

```powershell
docker build -t ordena .
docker run --rm -p 10000:10000 -e SESSION_COOKIE_SECURE=0 ordena
```

## Activar sugerencias con IA

La limpieza local funciona sin una clave API. Para sugerir categorías o revisar la calidad con OpenAI, configura `OPENAI_API_KEY` en el entorno del servidor y activa una o ambas opciones al subir un archivo. La revisión añade una columna `Revisión IA` con observaciones sobre vacíos o formatos dudosos; no cambia los valores originales. Opcionalmente, puedes cambiar el modelo con `OPENAI_MODEL` (por defecto `gpt-4o-mini`).

En PowerShell, configura la clave en la misma ventana donde ejecutas PHP:

```powershell
$env:OPENAI_API_KEY = "tu-clave"
php -S localhost:8000
```

La clave solo se lee en el servidor. Las peticiones envían a OpenAI los campos que no parecen identificativos según sus encabezados; los datos personales incluidos en campos de texto libre no se pueden detectar siempre. No actives la IA para archivos confidenciales: el proveedor procesa los datos según sus condiciones. Se admiten hasta 500 filas por operación con IA; si la API falla, la aplicación muestra el error en vez de ocultarlo.

## Cómo se usa

1. Sube un archivo `.csv` o `.xlsx`.
2. Si quieres, activa una de las opciones de IA. La categorización propone una categoría; la revisión añade notas sobre posibles datos incompletos o formatos dudosos.
3. Revisa la vista previa y descarga el CSV limpio.

La limpieza normaliza campos y quita duplicados. Sin IA, las categorías se sugieren con reglas locales. Las sugerencias de IA no modifican los datos originales; compruébalas antes de usar el archivo.

La IA es opcional y necesita `OPENAI_API_KEY`. Si la activas, parte del contenido se envía a OpenAI para analizarlo. Se excluyen columnas con encabezados que parecen identificativos, pero no se puede garantizar que el texto libre no incluya datos personales. No la uses con archivos confidenciales. En un servicio público sin login, cualquier visitante podría generar consumo de la API; configura un límite de gasto en OpenAI y no publiques la clave en el código.
