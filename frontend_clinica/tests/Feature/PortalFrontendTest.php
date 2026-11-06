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
        $response->assertSee('La API de especialidades todavía no está expuesta por Quarkus.');
        $response->assertSee('API pendiente');
        $response->assertDontSee('Medicina General');
    }

    public function test_login_form_is_available_with_csrf_protection(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Iniciar sesión')
            ->assertDontSee('Acceso aún no habilitado.')
            ->assertSee('name="_token"', false);
    }

    public function test_demo_credentials_are_not_rendered_outside_local_or_demo(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Acceso rápido de demostración')
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
            ->assertSee('Servicio pendiente.');

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
