Bash
cat << 'EOF' > README.md
# Proyecto Clínica - Guía de Desarrollo 🏥

Este repositorio contiene el código fuente del sistema para la Clínica, trabajando con un **Enfoque Híbrido** de desarrollo: La base de datos está dockerizada para evitar problemas de instalación, mientras que el código corre localmente con recarga en vivo (Live Reload).

## 🛠️ Stack Tecnológico
*   **Backend:** Quarkus (Java) usando arquitectura REST y Hibernate Panache.
*   **Frontend:** Laravel (PHP) usando vistas Blade clásicas y Bootstrap (Sin Node.js / Sin Vite).
*   **Base de Datos:** PostgreSQL 15 (Docker).

---

## 🚀 Cómo arrancar el proyecto (Paso a paso)

Para trabajar en este proyecto, **no necesitas instalar PostgreSQL** en tu computadora. Solo asegúrate de tener Docker abierto y sigue este orden estricto, usando 3 terminales distintas:

### 1. Levantar la Base de Datos (Terminal 1 - Raíz del proyecto)
En la carpeta principal del proyecto, ejecuta:
```bash
docker-compose up -d
Este comando descargará Postgres, lo encenderá y creará la base de datos clinica_db vacía de forma automática.

2. Levantar el Backend / Quarkus (Terminal 2 - Carpeta del backend)
Entra a la carpeta del backend y ejecuta:

Bash
.\mvnw.cmd compile quarkus:dev
El backend correrá en http://localhost:8080. Al encender, Quarkus se conectará a Docker y creará/actualizará todas las tablas de la base de datos automáticamente leyendo el código Java.

3. Levantar el Frontend / Laravel (Terminal 3 - Carpeta del frontend)
Entra a la carpeta del frontend y ejecuta:

Bash
php artisan serve
El sistema visual correrá en http://localhost:8000 y consumirá los datos del backend a través de peticiones HTTP.

🔑 Credenciales de Base de Datos
Para visualizar las tablas que genera Quarkus, usa DBeaver o pgAdmin conectándote con los siguientes datos:

Host: localhost

Puerto: 5432

Database: clinica_db

Usuario: root

Password: root

🏗️ Reglas del Flujo de Trabajo (¡Muy Importante!)
NO usamos scripts SQL: Trabajamos con un enfoque Code-First. Si necesitas agregar una tabla (ej. Cita), una columna o cambiar un tipo de dato, hazlo directamente en el código Java dentro de la carpeta entity/ del backend. Al guardar el archivo, Quarkus modificará la base de datos de Docker automáticamente sin borrar nuestros datos.

NO usamos Node.js ni NPM: El proyecto de Laravel está configurado para ser directo y rápido. No ejecutes npm install ni npm run dev. Usa vistas .blade.php normales y enlaza los estilos mediante CDN.

¿Error de contraseña en Postgres?: Si tu contenedor se bloquea o te dice FATAL: la autenticación password falló, significa que Postgres guardó una caché errónea. Resetea la base de datos por completo con estos comandos en la raíz del proyecto:

Bash
docker-compose down -v
docker-compose up -d
EOF


Una vez que presiones Enter y se genere el archivo, puedes subirlo todo a tu repositorio directamente ejecutando esto:

```bash
git add .
git commit -m "Docs: Añadido README.md con la guía de desarrollo y comandos"
git push origin main

cat << 'EOF' >> README.md

## 📂 Arquitectura y Estructura de Carpetas

Al trabajar con microservicios, el código está estrictamente dividido. Aquí tienes el mapa exacto de dónde va cada componente:

### 1. La Base de Datos (Infraestructura)
*   **Motor de BD:** `docker-compose.yml` (En la raíz del proyecto).
*   **Credenciales:** `clinica-backend/src/main/resources/application.properties` (Quarkus).
*   **Tablas (Modelos):** `clinica-backend/src/main/java/org/clinica/entity/` (Aquí van los `.java` que generan las tablas en Postgres).

### 2. El Backend / APIs (Quarkus)
Todo lo que es lógica de negocio, cálculos y creación de JSON ocurre aquí.
*   **Endpoints (APIs):** `clinica-backend/src/main/java/org/clinica/rest/`
*   *Ejemplo:* `PacienteResource.java` expone la ruta `GET /pacientes` en JSON.

### 3. El Frontend (Laravel)
Todo lo que ve el usuario y la navegación web ocurre aquí. Cero conexiones directas a la base de datos.
*   **Controladores:** `frontend_clinica/app/Http/Controllers/` (Consumen las APIs de Quarkus vía HTTP).
*   **Vistas (Pantallas):** `frontend_clinica/resources/views/` (Archivos `.blade.php` con HTML y Bootstrap).
*   **Rutas Web:** `frontend_clinica/routes/web.php` (Define las URLs que el usuario escribe en el navegador).
EOF