<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Organisation (et utilisateur) courants pour la requête ou le job en cours.
 *
 * Source unique alimentant les deux verrous d'isolation (et les rôles spatie, via
 * TenantTeamResolver) : le scope global Eloquent lit id(), et la RLS PostgreSQL lit les
 * variables de session app.organization_id / app.user_id.
 * Sans organisation fixée, les requêtes sur les tables métier ne renvoient rien.
 */
final class TenantContext
{
    private ?string $organizationId = null;

    private ?string $userId = null;

    public function id(): ?string
    {
        return $this->organizationId;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function set(?string $organizationId): void
    {
        $this->organizationId = $organizationId;
        $this->write('app.organization_id', $organizationId);
    }

    public function setUser(?string $userId): void
    {
        $this->userId = $userId;
        $this->write('app.user_id', $userId);
    }

    /**
     * Exécute $callback dans le contexte d'une organisation (null : aucun), puis rétablit le précédent.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(?string $organizationId, callable $callback): mixed
    {
        $previous = $this->organizationId;
        $this->set($organizationId);

        try {
            $result = $callback();
        } catch (Throwable $e) {
            // Dans une transaction en échec, la remise à zéro SQL échouerait aussi et masquerait
            // l'erreur d'origine ; l'annulation de la transaction rétablit de toute façon la valeur.
            rescue(fn () => $this->set($previous), report: false);
            throw $e;
        }

        $this->set($previous);

        return $result;
    }

    /**
     * Exécute $callback dans chaque organisation active (tâches planifiées) ; une organisation en
     * erreur est signalée et n'empêche pas les suivantes.
     *
     * @param  callable(string): mixed  $callback  reçoit l'identifiant de l'organisation
     */
    public function eachOrganization(callable $callback): void
    {
        DB::table('organizations')->whereNull('deleted_at')->orderBy('id')->pluck('id')
            ->each(function (string $organizationId) use ($callback) {
                try {
                    $this->run($organizationId, fn () => $callback($organizationId));
                } catch (Throwable $e) {
                    report($e);
                }
            });
    }

    /** Efface organisation et utilisateur, en une seule requête. */
    public function clear(): void
    {
        $this->organizationId = null;
        $this->userId = null;
        DB::select("SELECT set_config('app.organization_id', '', false), set_config('app.user_id', '', false)");
    }

    private function write(string $key, ?string $value): void
    {
        DB::select('SELECT set_config(?, ?, false)', [$key, $value ?? '']);
    }
}
