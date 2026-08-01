FROM nginx:1.27.4-alpine

RUN rm -rf /usr/share/nginx/html/*

COPY index.html /usr/share/nginx/html/index.html
COPY impressum.html /usr/share/nginx/html/impressum.html
COPY datenschutz.html /usr/share/nginx/html/datenschutz.html
COPY 404.html /usr/share/nginx/html/404.html
COPY styles.css /usr/share/nginx/html/styles.css
COPY robots.txt /usr/share/nginx/html/robots.txt
COPY sitemap.xml /usr/share/nginx/html/sitemap.xml
COPY assets/ /usr/share/nginx/html/assets/
COPY img/toon_logo.webp /usr/share/nginx/html/img/toon_logo.webp
COPY img/opt/ /usr/share/nginx/html/img/opt/

# Defense in depth: never retain a legacy/unhashed variant in the final image.
RUN find /usr/share/nginx/html/img/opt -type f | while IFS= read -r file; do \
      case "$(basename "$file")" in \
        *-i????????????-c????????????????????-r????????????-*.avif|\
        *-i????????????-c????????????????????-r????????????-*.webp|\
        *-i????????????-c????????????????????-r????????????-*.jpg) ;; \
        *) rm -f "$file" ;; \
      esac; \
    done

# Docker is deliberately static: production resolves this URL through PHP.
COPY tests/fixtures/current.json /usr/share/nginx/html/content/current.json

RUN chown -R nginx:nginx /usr/share/nginx/html && \
    chmod -R 755 /usr/share/nginx/html

COPY docker/nginx.conf /etc/nginx/nginx.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
