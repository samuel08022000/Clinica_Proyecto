package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.GeneratedValue;
import jakarta.persistence.GenerationType;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.Generated;
import org.hibernate.generator.EventType;

import java.time.OffsetDateTime;

/**
 * Tabla {@code public.notificaciones} (cola multicanal). La PK es
 * {@code bigint GENERATED ALWAYS AS IDENTITY}.
 */
@Entity
@Table(name = "notificaciones", schema = "public")
public class Notificacion extends PanacheEntityBase {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    @Column(name = "cod_not")
    public Long codNot;

    @Column(name = "cod_usu")
    public String codUsu;

    @Column(name = "cod_cit")
    public String codCit;

    /** Web, Email, WhatsApp, SMS. */
    @Column(name = "canal", nullable = false, length = 12)
    public String canal;

    @Column(name = "tipo_alerta", nullable = false, length = 40)
    public String tipoAlerta;

    @Column(name = "destinatario", length = 254)
    public String destinatario;

    @Column(name = "mensaje", nullable = false)
    public String mensaje;

    @Column(name = "fecha_programada", nullable = false)
    public OffsetDateTime fechaProgramada = OffsetDateTime.now();

    /** Pendiente, EnProceso, Enviada, Fallida, Cancelada. */
    @Column(name = "estado", nullable = false, length = 15)
    public String estado = "Pendiente";

    @Column(name = "intentos", nullable = false)
    public Short intentos = 0;

    @Column(name = "max_intentos", nullable = false)
    public Short maxIntentos = 5;

    @Column(name = "enviado_en")
    public OffsetDateTime enviadoEn;

    @Column(name = "en_proceso_desde")
    public OffsetDateTime enProcesoDesde;

    @Column(name = "ultimo_error")
    public String ultimoError;

    @Generated(event = EventType.INSERT)
    @Column(name = "creado_en", insertable = false, updatable = false)
    public OffsetDateTime creadoEn;
}
