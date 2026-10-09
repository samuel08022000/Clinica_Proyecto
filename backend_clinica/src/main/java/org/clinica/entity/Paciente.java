package org.clinica.entity;

import io.quarkus.hibernate.orm.panache.PanacheEntity;
import jakarta.persistence.Entity;
import jakarta.persistence.Column; // Importamos esta librería para las reglas

@Entity 
public class Paciente extends PanacheEntity {
    
    // Equivale a VARCHAR(50) y NOT NULL (no puede estar vacío)
    @Column(length = 50, nullable = false)
    public String nombre;

    @Column(length = 50, nullable = false)
    public String apellido;

    // unique = true evita que dos pacientes tengan el mismo carnet
    @Column(length = 15, unique = true, nullable = false)
    public String ci; 
    
    // Un número entero normal
    public int edad;

    // Para decimales (peso, altura, precios), usas double o float
    public double peso; 

    // Para contraseñas, le damos 255 caracteres porque cuando la encriptes (ej. con Bcrypt) se hará muy larga
    @Column(length = 255)
    public String password;
}