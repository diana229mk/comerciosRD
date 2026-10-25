<?php

namespace App\Filament\Pages;

use App\Enums\EcfEnvironment;
use App\Enums\EcfType;
use App\Models\Business;
use App\Models\EcfSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

class EcfSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Configuración e-CF';

    protected static ?string $title = 'Configuración de Facturación Electrónica';

    protected static ?string $navigationGroup = 'Facturación Electrónica';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.ecf-settings';

    protected static ?string $slug = 'configuracion-ecf';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array{ok: bool, ok_for_dgii?: bool, checks: array}|null */
    public ?array $checklist = null;

    public function mount(): void
    {
        $business = $this->currentBusiness();
        if (! $business) {
            return;
        }

        $settings = EcfSetting::forBusiness($business);
        $settings->refreshReadyFlag($business);
        $this->checklist = $settings->readinessChecklist($business);

        $this->form->fill([
            'environment' => $settings->environment instanceof EcfEnvironment
                ? $settings->environment->value
                : (string) ($settings->environment ?? 'test'),
            'certificate_path' => $settings->certificate_path,
            'certificate_password' => '', // no reexponer
            'has_certificate_password' => filled($settings->certificate_password),
            'api_username' => $settings->api_username,
            'api_password' => '',
            'has_api_password' => filled($settings->api_password),
            'default_ecf_type' => $settings->default_ecf_type ?: EcfType::CONSUMO->value,
            'auto_issue_on_sale' => (bool) $settings->auto_issue_on_sale,
            'require_buyer_rnc_for_31' => (bool) $settings->require_buyer_rnc_for_31,
            'contact_email' => $settings->contact_email,
            'notes' => $settings->notes,
            // snapshot de negocio (solo lectura / enlace)
            'business_rnc' => $business->rnc,
            'business_name' => $business->name,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Estado de preparación')
                    ->description('Checklist para emitir e-CF. La emisión a la DGII requiere certificado y certificación.')
                    ->schema([
                        Forms\Components\Placeholder::make('checklist_view')
                            ->label('')
                            ->content(fn (): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(
                                $this->renderChecklistHtml()
                            )),
                    ]),

                Forms\Components\Section::make('Ambiente DGII')
                    ->schema([
                        Forms\Components\Select::make('environment')
                            ->label('Ambiente')
                            ->options(EcfEnvironment::options())
                            ->required()
                            ->native(false)
                            ->helperText(fn ($state): string => EcfEnvironment::tryFrom((string) $state)?->description()
                                ?? 'Selecciona el ambiente de la DGII.'),

                        Forms\Components\Select::make('default_ecf_type')
                            ->label('Tipo e-CF por defecto')
                            ->options([
                                '32' => '32 - Factura de Consumo',
                                '31' => '31 - Factura de Crédito Fiscal',
                            ])
                            ->required()
                            ->native(false),

                        Forms\Components\Toggle::make('auto_issue_on_sale')
                            ->label('Emitir e-CF automáticamente al completar una venta')
                            ->helperText('Si está activo, se generará el comprobante interno al vender (aún sin envío a DGII).')
                            ->inline(false),

                        Forms\Components\Toggle::make('require_buyer_rnc_for_31')
                            ->label('Exigir RNC del comprador en e-CF 31 (Crédito Fiscal)')
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Certificado digital')
                    ->description('Archivo .p12 / .pfx emitido para facturación electrónica. La contraseña se guarda cifrada.')
                    ->schema([
                        Forms\Components\FileUpload::make('certificate_path')
                            ->label('Certificado (.p12 / .pfx)')
                            ->disk('private')
                            ->directory(fn (): string => app(\App\Services\PrivateDocumentStorage::class)
                                ->directoryForCurrentBusiness('ecf/certificates'))
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/x-pkcs12',
                                'application/pkcs12',
                                'application/octet-stream',
                            ])
                            ->maxSize(5120)
                            ->downloadable(false)
                            ->openable(false)
                            ->previewable(false)
                            ->helperText('Sube el certificado de la empresa. No se comparte entre negocios.')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('certificate_password')
                            ->label('Contraseña del certificado')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (Forms\Get $get): string => $get('has_certificate_password')
                                ? 'Ya hay una contraseña guardada. Déjalo vacío para no cambiarla.'
                                : 'Obligatoria para firmar el XML (fase de firma digital).'),

                        Forms\Components\Hidden::make('has_certificate_password'),
                    ])
                    ->columns(1),

                Forms\Components\Section::make('Credenciales API DGII (futuro)')
                    ->description('Se usarán al implementar el envío a TesteCF / CerteCF / Producción.')
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('api_username')
                            ->label('Usuario API')
                            ->maxLength(100),

                        Forms\Components\TextInput::make('api_password')
                            ->label('Contraseña API')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (Forms\Get $get): string => $get('has_api_password')
                                ? 'Ya hay contraseña API guardada. Vacío = no cambiar.'
                                : 'Opcional por ahora.'),

                        Forms\Components\Hidden::make('has_api_password'),

                        Forms\Components\TextInput::make('contact_email')
                            ->label('Email de contacto técnico')
                            ->email()
                            ->maxLength(255),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notas internas')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Datos del emisor (negocio)')
                    ->description('El RNC se edita en Configuración → Datos del Negocio.')
                    ->schema([
                        Forms\Components\Placeholder::make('business_name')
                            ->label('Nombre')
                            ->content(fn ($state): string => (string) ($this->data['business_name'] ?? '—')),

                        Forms\Components\Placeholder::make('business_rnc')
                            ->label('RNC')
                            ->content(fn (): string => filled($this->data['business_rnc'] ?? null)
                                ? (string) $this->data['business_rnc']
                                : '⚠️ Sin RNC — ve a Datos del Negocio'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        if (! \App\Support\EcfFeature::enabled()) {
            Notification::make()
                ->title('e-CF deshabilitado')
                ->body('La configuración e-CF está bloqueada temporalmente.')
                ->warning()
                ->send();

            return;
        }

        $business = $this->currentBusiness();
        if (! $business) {
            Notification::make()->title('Sin negocio activo')->danger()->send();

            return;
        }

        $data = $this->form->getState();
        $settings = EcfSetting::forBusiness($business);

        $payload = [
            'environment' => $data['environment'] ?? 'test',
            'default_ecf_type' => $data['default_ecf_type'] ?? '32',
            'auto_issue_on_sale' => (bool) ($data['auto_issue_on_sale'] ?? false),
            'require_buyer_rnc_for_31' => (bool) ($data['require_buyer_rnc_for_31'] ?? true),
            'api_username' => $data['api_username'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        // Certificado: path del FileUpload
        $cert = $data['certificate_path'] ?? null;
        if (is_array($cert)) {
            $cert = $cert[0] ?? null;
        }
        if (is_string($cert) && $cert !== '') {
            $payload['certificate_path'] = $cert;
        }

        if (filled($data['certificate_password'] ?? null)) {
            $payload['certificate_password'] = $data['certificate_password'];
        }

        if (filled($data['api_password'] ?? null)) {
            $payload['api_password'] = $data['api_password'];
        }

        $settings->fill($payload);
        $settings->business_id = $business->getKey();
        $settings->save();
        $settings->refreshReadyFlag($business);

        $this->checklist = $settings->readinessChecklist($business);
        $this->data['has_certificate_password'] = filled($settings->certificate_password);
        $this->data['has_api_password'] = filled($settings->api_password);
        $this->data['certificate_password'] = '';
        $this->data['api_password'] = '';

        Notification::make()
            ->title('Configuración e-CF guardada')
            ->body($settings->is_ready
                ? 'El negocio está listo para emisión interna de e-CF.'
                : 'Guardado. Revisa el checklist: aún faltan requisitos.')
            ->success()
            ->send();
    }

    protected function renderChecklistHtml(): string
    {
        $report = $this->checklist
            ?? ['ok' => false, 'ok_for_dgii' => false, 'checks' => []];

        $rows = '';
        foreach ($report['checks'] ?? [] as $check) {
            $icon = $check['ok'] ? '✅' : '❌';
            $msg = e($check['message'] ?? '');
            $rows .= "<li class=\"py-1\">{$icon} {$msg}</li>";
        }

        $internal = ($report['ok'] ?? false)
            ? '<span class="text-success-600 font-semibold">Listo para emisión interna</span>'
            : '<span class="text-warning-600 font-semibold">Faltan requisitos para emitir</span>';

        $dgii = ($report['ok_for_dgii'] ?? false)
            ? '<span class="text-success-600 font-semibold">Certificado listo (firma/envío pendiente de implementar)</span>'
            : '<span class="text-gray-500">Envío a DGII: pendiente de certificado y desarrollo de firma/API</span>';

        return <<<HTML
<div class="space-y-3 text-sm">
  <div>{$internal}</div>
  <div>{$dgii}</div>
  <ul class="list-none space-y-0.5 mt-2">{$rows}</ul>
</div>
HTML;
    }

    protected function currentBusiness(): ?Business
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Business ? $tenant : null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (! \App\Support\EcfFeature::enabled()) {
            return false;
        }

        $tenant = Filament::getTenant();
        $user = auth()->user();

        if (! $tenant instanceof Business || ! $user instanceof User) {
            return false;
        }

        if (! $tenant->hasModule('electronic_invoicing')) {
            return false;
        }

        return $user->hasRoleInBusiness(['owner', 'admin']);
    }

    public static function canAccess(): bool
    {
        return \App\Support\EcfFeature::enabled() && static::shouldRegisterNavigation();
    }
}
