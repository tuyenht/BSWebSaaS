<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\SwitchesLocale;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Actions\AcceptInvitation as AcceptInvitationAction;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The page an invitation mail links to, on the invited site's own host — built in the existing
 * sign-in frame (owner decision 24/09 Q9: new screens of the sign-in area follow the login's look).
 *
 * It shows who invites the visitor to which site and with what roles, then offers exactly one road:
 *  - signed in as the invited address → "Join";
 *  - the address has an account but the visitor is not signed in → sign in the normal way (password
 *    + second factor); the login returns here afterwards (session intended URL);
 *  - signed in as another account → say so, and offer to sign out;
 *  - the address has no account → name + password to create it; the new account then signs in
 *    the normal way (the second factor stays mandatory for every login).
 * A used, withdrawn or expired link says so in plain words, with the fix (ask for a new one).
 */
#[Layout('admin::layouts.auth')]
final class AcceptInvitation extends Component
{
    use SwitchesLocale;

    #[Locked]
    public string $token = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function boot(): void
    {
        // Never on someone's behalf: staff signed in for support cannot change this.
        app(SupportSession::class)->forbid();
    }

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function join(): mixed
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return $this->signIn();
        }

        try {
            app(AcceptInvitationAction::class)->asSignedInUser($this->token, $user);
        } catch (InvitationRejectedException $e) {
            $this->addError('invitation', __('rbac.invitation.error_'.$e->reason));

            return null;
        }

        // Landing inside the site's admin is the confirmation — the member is in.
        return $this->redirectRoute('admin.dashboard', navigate: false);
    }

    public function createAccount(): mixed
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        try {
            app(AcceptInvitationAction::class)->withNewAccount($this->token, $this->name, $this->password);
        } catch (InvitationRejectedException $e) {
            $this->reset('password', 'password_confirmation');
            $this->addError('invitation', __('rbac.invitation.error_'.$e->reason));

            return null;
        }

        session()->flash('status', __('rbac.invitation.account_created'));

        return $this->redirectRoute('admin.login', navigate: false);
    }

    /** Sign in the normal way, then come back here to join. */
    public function signIn(): mixed
    {
        session()->put('url.intended', route('admin.invitations.accept', ['token' => $this->token]));

        return $this->redirectRoute('admin.login', navigate: false);
    }

    public function signOut(): mixed
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return $this->redirectRoute('admin.invitations.accept', ['token' => $this->token], navigate: false);
    }

    public function render(): View
    {
        $view = view('admin::livewire.accept-invitation', $this->viewData());
        $view->title(__('rbac.invitation.page_title'));

        return $view;
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        try {
            $invitation = app(AcceptInvitationAction::class)->find($this->token);
        } catch (InvitationRejectedException $e) {
            return ['mode' => 'closed', 'reason' => $e->reason];
        }

        $user = Auth::user();
        $invited = Str::lower($invitation->email);

        $mode = match (true) {
            $user instanceof User && Str::lower($user->email) === $invited => 'join',
            $user instanceof User => 'wrong_account',
            User::query()->where('email', $invited)->exists() => 'sign_in',
            default => 'create',
        };

        return [
            'mode' => $mode,
            'invitation' => $invitation,
            'siteName' => $this->siteName(),
            'inviterName' => $invitation->inviter()->value('name'),
            'roleLabels' => $invitation->roles()->get()->map(fn (Role $role): string => RoleLabel::for($role))->all(),
            'signedInAs' => $user instanceof User ? $user->email : null,
        ];
    }

    private function siteName(): string
    {
        $name = Site::query()->whereKey(app(CurrentSite::class)->id())->value('name');

        return is_string($name) && $name !== '' ? $name : request()->getHost();
    }
}
