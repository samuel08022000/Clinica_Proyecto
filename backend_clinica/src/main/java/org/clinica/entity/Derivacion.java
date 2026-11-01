package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.Generated;
import org.hibernate.generator.EventType;

import java.time.OffsetDateTime;

/**
 * Tabla {@code public.derivaciones}. Para crearlas use
 * {@code CitaFunctionRepository.crearDerivacion(...)}.
 */
@Entity
@Table(name = "derivaciones", schema = "public")
public class Derivacion extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_der", insertable = false, updatable = false)
    public String codDer;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "cod_cit_origen", nullable = false)
    public String codCitOrigen;

    @Column(name = "cod_cit_hija", unique = true)
    public String codCitHija;

    @Column(name = "cod_med_emisor", nullable = false)
    public String codMedEmisor;

    @Column(name = "cod_esp_destino", nullable = false)
    public String codEspDestino;

    @Column(name = "cod_med_destino")
    public String codMedDestino;

    @Column(name = "motivo_derivacion", nullable = false)
    public String motivoDerivacion;

    /** Pendiente, Agendada, Completada, Cancelada. */
    @Column(name = "estado", nullable = false, length = 15)
    public String estado = "Pendiente";

    @Generated(event = EventType.INSERT)
    @Column(name = "fecha_derivacion", insertable = false, updatable = false)
    public OffsetDateTime fechaDerivacion;
}
