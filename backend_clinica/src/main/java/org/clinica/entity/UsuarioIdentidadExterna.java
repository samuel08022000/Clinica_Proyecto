package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntityBase;
import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import org.hibernate.annotations.Generated;
import org.hibernate.generator.EventType;

import java.time.OffsetDateTime;

@Entity
@Table(name = "usuarios_identidades_externas", schema = "public")
public class UsuarioIdentidadExterna extends PanacheEntityBase {

    @Id
    @Generated(event = EventType.INSERT)
    @Column(name = "cod_identidad", insertable = false, updatable = false)
    public String codIdentidad;

    @Generated(event = EventType.INSERT)
    @Column(name = "secuencial", insertable = false, updatable = false)
    public Long secuencial;

    @Column(name = "cod_usu", nullable = false)
    public String codUsu;

    @Column(name = "proveedor", nullable = false, length = 20)
    public String proveedor;

    @Column(name = "identificador_externo", nullable = false, length = 255)
    public String identificadorExterno;

    @Column(name = "email_proveedor", length = 254)
    public String emailProveedor;

    @Column(name = "email_verificado", nullable = false)
    public Boolean emailVerificado = false;

    @Generated(event = EventType.INSERT)
    @Column(name = "vinculado_en", insertable = false, updatable = false)
    public OffsetDateTime vinculadoEn;

    @Generated(event = EventType.INSERT)
    @Column(name = "actualizado_en", insertable = false)
    public OffsetDateTime actualizadoEn;
}
