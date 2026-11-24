# Página web de la app (PWA) para el servidor: la usa docker-compose.yml en la raíz (Dokploy).
# La APK no sale de aquí: se arma en el PC (ver README).

# --- Construcción ---
FROM node:24-alpine AS construccion
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
# Dirección de la API, por ejemplo https://api.su-dominio.co/api (la pone docker-compose.yml)
ARG VITE_API_URL
RUN test -n "$VITE_API_URL" || (echo "Falta VITE_API_URL" >&2 && exit 1)
ENV VITE_API_URL=$VITE_API_URL
RUN npx vue-tsc -b && npx vite build --mode production

# --- Servidor de archivos ---
# "stable" se actualiza con cada versión estable de nginx (arreglos de seguridad incluidos)
FROM nginx:stable-alpine
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=construccion /app/dist /usr/share/nginx/html
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -q --spider http://127.0.0.1/ || exit 1
