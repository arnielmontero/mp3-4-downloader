# Static frontend + reverse proxy to PHP-FPM. Build context: repository root.
FROM nginxinc/nginx-unprivileged:1.27-alpine
USER root
RUN apk add --no-cache curl
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY frontend/ /usr/share/nginx/html/
RUN chown -R nginx:nginx /usr/share/nginx/html
USER nginx
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/api/health >/dev/null || exit 1
