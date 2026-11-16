<?php

namespace Tests\Feature;

use Tests\TestCase;

class PortalFrontendTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_portal_renders_without_inventing_specialties(): void
    {
        $response = $this->get(route('home'));

        $response->assertStatus(200);
        $response->assertSee('Tus citas, en orden');
        $response->assertSee('La API pública de especialidades todavía no está conectada al portal.');
        $response->assertSeeInOrder(['Reservas web', 'En desarrollo', 'Acreditación con QR', 'Disponible', 'Recordatorio 24 h antes', 'Próximamente', 'Consulta de citas', 'En desarrollo']);
        $response->assertSee('Aún no se envían recordatorios automáticos.');
        $response->assertDontSee('Medicina General');
    }

    public function test_classic_portal_keeps_its_public_sections_and_working_navigation(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSeeInOrder(['id="inicio"', 'id="especialidades"', 'id="servicios"', 'id="contacto"'], false)
            ->assertSee('booking-window__note', false)
            ->assertSee('no representa cupos disponibles')
            ->assertSee('images/clinic/appointment-calendar.png', false)
            ->assertSee('images/clinic/reception.png', false)
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('href="'.route('register').'"', false)
            ->assertSee('aria-describedby="reservation-status"', false)
            ->assertDontSee('data-demo-user');
    }

    public function test_login_form_is_available_with_csrf_protection(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Iniciar sesión')
            ->assertDontSee('Acceso aún no habilitado.')
            ->assertSee('name="_token"', false);
    }

    public function test_demo_accounts_are_absent_even_in_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config()->set('clinic.demo.enabled', true);
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Acceso rápido de demostración')
            ->assertDontSee('data-demo-user')
            ->assertDontSee('samuel.paciente@unifranz.edu.bo')
            ->assertSee('No hay accesos predeterminados.')
            ->assertDontSee('$2a$12$');
    }

    public function test_registration_and_recovery_interfaces_render_with_csrf(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Crea tu cuenta de paciente')
            ->assertSee('Verificación por correo')
            ->assertSee('Crear cuenta y emitir código')
            ->assertSee('name="_token"', false);

        $this->get(route('verification.email'))
            ->assertOk()
            ->assertSee('Confirma tu correo')
            ->assertSee('Solicitar código')
            ->assertSee('name="_token"', false);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Recupera tu contraseña')
            ->assertSee('Por privacidad, la respuesta será la misma');

    }

    public function test_registration_requires_the_database_fields_before_backend_handoff(): void
    {
        $this->post(route('register.store'), [])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'ci',
                'nombre_completo',
                'fecha_nacimiento',
                'telefono',
                'email',
                'ciudad_residencia',
                'password',
            ]);
    }
}
