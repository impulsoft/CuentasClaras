# Copia diaria de la base de datos (servicio "respaldo" de docker-compose.yml).
# Usa la misma versión de MySQL para que mysqldump sea compatible.
FROM mysql:8.4
# Hora de Colombia (UTC-5, sin horario de verano). Se escribe así porque la imagen puede no traer
# la base de zonas horarias; con "America/Bogota" sin ella la copia saldría a las 9:30 p. m.
ENV TZ=COT5
COPY respaldar.sh /usr/local/bin/respaldar
RUN sed -i 's/\r$//' /usr/local/bin/respaldar && chmod +x /usr/local/bin/respaldar
ENTRYPOINT ["respaldar"]
