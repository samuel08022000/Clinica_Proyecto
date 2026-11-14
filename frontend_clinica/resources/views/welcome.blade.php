@extends('layouts.app')

@section('title', $clinic['identity']['name'].' | Citas médicas')
@section('meta_description', $clinic['identity']['description'])
@section('body_class', 'portal-page')

@section('content')
    <main id="contenido-principal">
        <div class="portal-shell">
            <aside class="visual-stage" aria-label="Ventana de reserva de citas">
                <div class="visual-stage__media">
                    <img
                        src="{{ asset('images/clinic/appointment-calendar.png') }}"
                        alt="Profesional de salud organiza una agenda de citas"
                        width="1120"
                        height="1400"
                        fetchpriority="high"
                        data-stage-image
                    >
                </div>

                <div class="booking-window" data-booking-window>
                    <div class="booking-window__heading">
                        <p>Ventana de reserva</p>
                        <strong>Hoy → 14 días</strong>
                    </div>
                    <ol class="booking-window__days" aria-label="Fechas comprendidas en la ventana móvil de reserva" data-booking-days>
                        <li><span>Hoy</span><strong>—</strong></li>
                        <li><span>+1</span><strong>—</strong></li>
                        <li><span>+2</span><strong>—</strong></li>
                        <li><span>+3</span><strong>—</strong></li>
                        <li><span>+4</span><strong>—</strong></li>
                        <li><span>+5</span><strong>—</strong></li>
                        <li><span>+6</span><strong>—</strong></li>
                        <li><span>+7</span><strong>—</strong></li>
                        <li><span>+8</span><strong>—</strong></li>
                        <li><span>+9</span><strong>—</strong></li>
                        <li><span>+10</span><strong>—</strong></li>
                        <li><span>+11</span><strong>—</strong></li>
                        <li><span>+12</span><strong>—</strong></li>
                        <li><span>+13</span><strong>—</strong></li>
                        <li><span>+14</span><strong>—</strong></li>
                    </ol>
                    <p class="booking-window__note">Este rango explica la regla del sistema; no representa cupos disponibles.</p>
                </div>
            </aside>

            <div class="portal-column">
                <header class="site-header" data-site-header>
                    <a class="brand" href="{{ route('home') }}" aria-label="{{ $clinic['identity']['name'] }}, inicio">
                        <svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true">
                            <path d="M20 5v30M5 20h30" />
                            <path d="M11 11h18v18H11z" />
                        </svg>
                        <span>{{ $clinic['identity']['short_name'] }}</span>
                    </a>

                    <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="site-navigation" data-menu-toggle>
                        <span>Menú</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h16M4 16h16" /></svg>
                    </button>

                    <nav id="site-navigation" class="site-nav" aria-label="Navegación principal" data-menu>
                        <a href="#inicio">Inicio</a>
                        <a href="#especialidades">Especialidades</a>
                        <a href="#servicios">Servicios</a>
                        <a href="#contacto">Contacto</a>
                        <a class="site-nav__action" href="{{ route('login') }}">Portal del paciente</a>
                    </nav>
                </header>

                <section id="inicio" class="portal-hero reveal-group" aria-labelledby="portal-title">
                    <div class="hero-copy reveal">
                        <h1 id="portal-title">Tus citas, en orden</h1>
                        <p class="hero-copy__lead">Un acceso claro a las especialidades, las reservas y el seguimiento de citas de la clínica.</p>
                        <div class="hero-actions">
                            <a class="button button--primary" href="{{ route('login') }}" aria-describedby="reservation-status">Reservar cita</a>
                            <a class="text-link" href="#especialidades">Conocer especialidades</a>
                        </div>
                    </div>

                    <div class="hero-system-note reveal">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9 9 9 0 0 0-9-9Zm0 5v5l3 2" /></svg>
                        <p id="reservation-status">La base de datos limita la agenda desde hoy hasta 14 días después. La reserva en recepción está disponible; la reserva web desde el panel del paciente está en desarrollo.</p>
                    </div>
                </section>

                <section id="especialidades" class="portal-section specialties-section reveal-group" aria-labelledby="specialties-title">
                    <div class="section-heading reveal">
                        <h2 id="specialties-title">Especialidades</h2>
                        <p>El catálogo se muestra únicamente cuando Quarkus expone especialidades activas.</p>
                    </div>

                    @if ($specialtiesState === 'ready')
                        <div class="specialty-list" data-specialties-state="ready">
                            @foreach ($specialties as $specialty)
                                <details class="specialty-row reveal" @if ($loop->first) open @endif>
                                    <summary>
                                        <span class="specialty-row__icon">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h4l2-5 4 10 2-5h6" /></svg>
                                        </span>
                                        <span>{{ $specialty['name'] }}</span>
                                        <span class="specialty-row__toggle" aria-hidden="true">+</span>
                                    </summary>
                                    <div class="specialty-row__content">
                                        <p>{{ $specialty['description'] ?: 'Descripción no registrada en el backend.' }}</p>
                                        @if ($specialty['duration'])
                                            <span>Duración referencial: {{ $specialty['duration'] }} min</span>
                                        @endif
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    @else
                        <div class="data-state reveal" data-specialties-state="{{ $specialtiesState }}" role="status">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 3v18M3 12h18" />
                                <path d="M5 5h14v14H5z" />
                            </svg>
                            <div>
                                <h3>{{ $specialtiesState === 'empty' ? 'Catálogo sin resultados' : 'Catálogo aún no conectado' }}</h3>
                                <p>{{ $specialtiesMessage }}</p>
                                @if ($specialtiesState === 'error')
                                    <a class="text-link" href="{{ route('home') }}#especialidades">Reintentar consulta</a>
                                @endif
                            </div>
                        </div>
                    @endif
                </section>

                <section id="servicios" class="portal-section capability-section reveal-group" aria-labelledby="services-title">
                    <div class="section-heading reveal">
                        <h2 id="services-title">Servicios del portal</h2>
                        <p>Cada capacidad indica con precisión qué parte existe y cuál está en desarrollo o llegará próximamente.</p>
                    </div>

                    <div class="capability-menu">
                        <article class="capability-item reveal">
                            <div>
                                <h3>Reservas web</h3>
                                <p>La base aplica una ventana móvil de hoy a 14 días y evita reservas solapadas. El acceso desde el panel del paciente está en desarrollo.</p>
                            </div>
                            <span class="status-label status-label--pending">En desarrollo</span>
                        </article>
                        <article class="capability-item reveal">
                            <div>
                                <h3>Acreditación con QR</h3>
                                <p>La base genera un token firmado y valida el check-in en recepción. Disponible para el personal autorizado de Secretaría.</p>
                            </div>
                            <span class="status-label status-label--pending">Disponible</span>
                        </article>
                        <article class="capability-item reveal">
                            <div>
                                <h3>Recordatorio 24 h antes</h3>
                                <p>La cola se programa en PostgreSQL; falta el servicio que entregue correo, SMS o WhatsApp. Aún no se envían recordatorios automáticos.</p>
                            </div>
                            <span class="status-label status-label--pending">Próximamente</span>
                        </article>
                        <article class="capability-item reveal">
                            <div>
                                <h3>Consulta de citas</h3>
                                <p>La vista de historial existe en la base y su consulta administrativa está disponible en recepción. La consulta desde el panel del paciente está en desarrollo.</p>
                            </div>
                            <span class="status-label status-label--pending">En desarrollo</span>
                        </article>
                    </div>
                </section>

                <section class="portal-section access-section reveal-group" aria-labelledby="access-title">
                    <div class="section-heading reveal">
                        <h2 id="access-title">Un acceso para cada rol</h2>
                        <p>Pacientes, médicos, secretaría y administración comparten el ingreso; el sistema conserva la autorización por rol.</p>
                    </div>

                    <div class="role-lines" aria-label="Roles disponibles">
                        <span class="reveal">Paciente</span>
                        <span class="reveal">Secretaría</span>
                        <span class="reveal">Médico</span>
                        <span class="reveal">Administración</span>
                    </div>
                    <a class="button button--primary reveal" href="{{ route('login') }}">Iniciar sesión</a>
                </section>
            </div>
        </div>

        <section class="portal-cta reveal-group" aria-labelledby="cta-title">
            <img
                src="{{ asset('images/clinic/reception.png') }}"
                alt="Recepción médica organizada y preparada para orientar a los pacientes"
                width="1680"
                height="945"
                loading="lazy"
            >
            <div class="portal-cta__scrim" aria-hidden="true"></div>
            <div class="portal-cta__copy reveal">
                <h2 id="cta-title">Tu próxima cita empieza aquí</h2>
                <p>Ingresa al portal o crea una cuenta de paciente. La reserva web desde tu panel está en desarrollo; puedes solicitar un turno en recepción.</p>
                <a class="button button--light" href="{{ route('register') }}">Crear cuenta</a>
            </div>
        </section>

        <section id="contacto" class="contact-band reveal-group" aria-labelledby="contact-title">
            <div class="contact-band__statement reveal">
                <h2 id="contact-title">Atención e información</h2>
                <p>Los datos institucionales se publican desde la configuración del entorno para evitar información no oficial.</p>
            </div>

            <img
                class="contact-band__image reveal"
                src="{{ asset('images/clinic/appointment-calendar.png') }}"
                alt="Detalle de una agenda médica organizada"
                width="1120"
                height="1400"
                loading="lazy"
            >

            <div class="contact-band__details reveal">
                <h3>Contacto</h3>
                @if ($clinic['contact']['phone'])
                    <a href="tel:{{ preg_replace('/[^+0-9]/', '', $clinic['contact']['phone']) }}">{{ $clinic['contact']['phone'] }}</a>
                @endif
                @if ($clinic['contact']['email'])
                    <a href="mailto:{{ $clinic['contact']['email'] }}">{{ $clinic['contact']['email'] }}</a>
                @endif
                @if (! $clinic['contact']['phone'] && ! $clinic['contact']['email'])
                    <p class="configuration-note">Teléfono y correo pendientes de configuración.</p>
                @endif
                @if ($clinic['contact']['address'] || $clinic['contact']['city'])
                    <p>{{ collect([$clinic['contact']['address'], $clinic['contact']['city']])->filter()->join(', ') }}</p>
                @else
                    <p class="configuration-note">Dirección pendiente de configuración.</p>
                @endif
            </div>

            <div class="contact-band__hours reveal">
                <h3>Horarios</h3>
                @if (collect($clinic['hours'])->filter()->isNotEmpty())
                    <dl>
                        @if ($clinic['hours']['weekdays'])<div><dt>Lunes a viernes</dt><dd>{{ $clinic['hours']['weekdays'] }}</dd></div>@endif
                        @if ($clinic['hours']['saturday'])<div><dt>Sábado</dt><dd>{{ $clinic['hours']['saturday'] }}</dd></div>@endif
                        @if ($clinic['hours']['sunday'])<div><dt>Domingo</dt><dd>{{ $clinic['hours']['sunday'] }}</dd></div>@endif
                    </dl>
                @else
                    <p class="configuration-note">Horarios pendientes de configuración.</p>
                @endif
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="site-footer__image">
            <img
                src="{{ asset('images/clinic/reception.png') }}"
                alt="Espacio de recepción médica"
                width="1680"
                height="945"
                loading="lazy"
            >
        </div>
        <div class="site-footer__content">
            <a class="brand brand--footer" href="{{ route('home') }}">
                <svg class="brand__mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 5v30M5 20h30" /><path d="M11 11h18v18H11z" /></svg>
                <span>{{ $clinic['identity']['short_name'] }}</span>
            </a>
            <nav aria-label="Navegación del pie">
                <a href="#inicio">Inicio</a>
                <a href="#especialidades">Especialidades</a>
                <a href="#servicios">Servicios</a>
                <a href="{{ route('login') }}">Acceso</a>
                <a href="#contacto">Contacto</a>
            </nav>
            <div class="site-footer__legal">
                <p>© {{ now()->year }} {{ $clinic['identity']['name'] }}.</p>
                <p>Portal Laravel conectado a servicios Quarkus.</p>
            </div>
        </div>
    </footer>
@endsection
