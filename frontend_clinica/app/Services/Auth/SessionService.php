<?php

namespace App\Services\Auth;

use App\Models\Usuario;
use Illuminate\Auth\AuthManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;

final class SessionService
{
    public function __construct(private AuthManager $auth, private DatabaseManager $database) {}

    public function establish(Request $request, Usuario $user, object $account): void
    {
        $this->revokeAllForUser($user->cod_usu);
        $this->auth->guard('web')->login($user, false);
        $request->session()->regenerate();
        $request->session()->forget('auth_token');
        $request->session()->put([
            'auth_authority' => 'laravel',
            'auth_expires_at' => now()->addMinutes((int) config('session.lifetime', 120))->toIso8601String(),
            'cod_usu' => $account->cod_usu,
            'nombre_usuario' => $account->nombre_completo,
            'email_usuario' => $account->email,
            'cod_rol' => $account->cod_rol,
            'nombre_rol' => $account->nombre_rol,
            'rol_slug' => self::roleSlug($account->cod_rol),
            'estado_cuenta' => $account->perfil_estado,
        ]);
    }

    public function logout(Request $request): void
    {
        $this->auth->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function revokeAllForUser(string $userId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }
        $connection = config('session.connection') ?: config('database.default');
        $this->database->connection($connection)->table((string) config('session.table', 'sessions'))
            ->where('user_id', $userId)->delete();
    }

    public static function roleSlug(string $role): string
    {
        return match ($role) {
            'ROL001' => 'paciente', 'ROL002' => 'secretaria',
            'ROL003' => 'medico', 'ROL004' => 'administracion', default => '',
        };
    }
}
