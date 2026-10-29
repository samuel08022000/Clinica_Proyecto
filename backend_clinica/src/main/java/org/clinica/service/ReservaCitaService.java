public List<VistaAgendaDisponibilidad> buscarCuposDisponibles(String codEsp, String codMed, LocalDate fecha) {
    return VistaAgendaDisponibilidad.list(
        "codEsp = ?1 and codMed = ?2 and fecha = ?3 and estadoDisponibilidad = 'Disponible' order by horaInicio",
        codEsp, codMed, fecha
    );
}