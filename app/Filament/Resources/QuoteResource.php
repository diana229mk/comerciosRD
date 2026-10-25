<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\QuoteResource\Pages;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Quote;
use App\Services\QuoteService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;

class QuoteResource extends Resource
{
    use ChecksBusinessModule;

    protected static ?string $model = Quote::class;
    protected static string $businessModule = 'quotes';
    protected static string $viewPermission = 'view_orders';
    protected static string $createPermission = 'create_orders';
    protected static string $editPermission = 'edit_orders';
    protected static bool $cashierNavigation = true;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Cotizaciones';
    protected static ?string $modelLabel = 'Cotización';
    protected static ?string $pluralModelLabel = 'Cotizaciones';
    protected static ?string $navigationGroup = 'Ventas';
    protected static ?int $navigationSort = 20;
    protected static ?string $recordTitleAttribute = 'code';
    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function getNavigationLabel(): string
    {
        return match (filament()->getTenant()?->business_type) {
            'repuestos' => 'Cotizaciones',
            'ferreteria' => 'Cotizaciones / Órdenes',
            default => 'Cotizaciones',
        };
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cabecera')->schema([
                Forms\Components\TextInput::make('code')->label('Código')->disabled()->dehydrated(false),
                Forms\Components\Select::make('customer_id')->label('Cliente / Taller')
                    ->options(fn () => Customer::query()->orderBy('name')->limit(500)->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                Forms\Components\TextInput::make('customer_name')->label('Nombre libre')->maxLength(120),
                Forms\Components\Select::make('price_list_id')->label('Lista de precios')
                    ->options(fn () => PriceList::query()->where('is_active', true)->pluck('name', 'id')),
                Forms\Components\DatePicker::make('issue_date')->label('Fecha')->default(now()),
                Forms\Components\DatePicker::make('valid_until')->label('Válida hasta')->default(now()->addDays(15)),
                Forms\Components\Select::make('status')->options([
                    'draft' => 'Borrador',
                    'sent' => 'Enviada',
                    'accepted' => 'Aceptada',
                    'converted' => 'Convertida a venta',
                    'expired' => 'Vencida',
                    'cancelled' => 'Cancelada',
                ])->default('draft')->disabled(fn ($record) => $record?->status === 'converted'),
                Forms\Components\Textarea::make('notes')->label('Notas / condiciones')->rows(2)->columnSpanFull(),
            ])->columns(2),
            Forms\Components\Section::make('Ítems')->schema([
                Forms\Components\Repeater::make('items')->relationship('items')->schema([
                    Forms\Components\Select::make('product_id')->label('Repuesto / Producto')
                        ->options(fn () => Product::query()->orderBy('name')->limit(500)->pluck('name', 'id'))
                        ->searchable()->required()
                        ->afterStateUpdated(function ($state, $set) {
                            if ($p = Product::find($state)) {
                                $set('product_name', $p->name);
                                $set('unit_price', (float) $p->price);
                                $set('unit_code', $p->unit);
                                $set('oem_code', $p->oem_code);
                            }
                        })->reactive(),
                    Forms\Components\TextInput::make('product_name')->label('Descripción')->required(),
                    Forms\Components\TextInput::make('oem_code')->label('OEM')
                        ->visible(fn () => filament()->getTenant()?->business_type === 'repuestos'),
                    Forms\Components\TextInput::make('quantity')->label('Cant.')->numeric()->default(1)->required(),
                    Forms\Components\TextInput::make('unit_price')->label('Precio')->numeric()->required(),
                ])->columns(4)->defaultItems(1)->disabled(fn ($record) => $record?->status === 'converted'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label('Nº')->searchable()->weight('bold'),
            Tables\Columns\TextColumn::make('customer.name')->label('Cliente / Taller')->searchable(),
            Tables\Columns\TextColumn::make('issue_date')->label('Fecha')->date(),
            Tables\Columns\TextColumn::make('valid_until')->label('Válida hasta')->date()->color(fn ($record) => $record->isExpired() ? 'danger' : null),
            Tables\Columns\TextColumn::make('total')->money('DOP')->sortable(),
            Tables\Columns\BadgeColumn::make('status')
                ->label('Estado')
                ->colors([
                    'gray' => 'draft',
                    'info' => 'sent',
                    'success' => ['accepted', 'converted'],
                    'danger' => ['expired', 'cancelled'],
                    'warning' => 'cancelled',
                ])
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'draft' => 'Borrador',
                    'sent' => 'Enviada',
                    'accepted' => 'Aceptada',
                    'converted' => 'Convertida',
                    'expired' => 'Vencida',
                    'cancelled' => 'Cancelada',
                    default => $state,
                }),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Action::make('confirmar_ticket')
                ->label('Confirmar y emitir ticket')
                ->icon('heroicon-o-ticket')
                ->color('success')
                ->visible(fn (Quote $r) => in_array($r->status, ['draft', 'sent', 'accepted'], true) && ! $r->converted_sale_id)
                ->action(function (Quote $r) {
                    if (in_array($r->status, ['draft', 'sent'], true)) {
                        $r->update(['status' => 'accepted']);
                    }
                    Notification::make()
                        ->title('Preventa confirmada')
                        ->body('Se emite el ticket. El cobro lo hace caja.')
                        ->success()
                        ->send();

                    return app(\App\Services\QuotePdfService::class)->download($r);
                }),
            Action::make('pdf')->label('PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->action(fn (Quote $r) => app(\App\Services\QuotePdfService::class)->download($r)),
            Action::make('cobrar')->label('Cobrar en caja')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (Quote $r) => (auth()->user()?->canPerform('create_sales') ?? false)
                    && in_array($r->status, ['draft', 'sent', 'accepted'], true)
                    && ! $r->converted_sale_id)
                ->url(function (Quote $r): string {
                    $slug = filament()->getTenant()?->slug;

                    return url('/caja/'.$slug.'?quote='.$r->id);
                }),
            Action::make('convert')->label('Pasar a venta')
                ->icon('heroicon-o-arrow-right-circle')
                ->color('gray')
                ->visible(fn (Quote $r) => (auth()->user()?->canPerform('create_sales') ?? false)
                    && ! (auth()->user()?->isStrictCashier() ?? false)
                    && in_array($r->status, ['draft', 'sent', 'accepted'], true)
                    && ! $r->converted_sale_id)
                ->requiresConfirmation()
                ->modalHeading('Convertir cotización en venta')
                ->modalDescription('Se creará una venta con los mismos productos. El cajero puede cobrarla en caja sin este paso.')
                ->action(function (Quote $r) {
                    $sale = app(QuoteService::class)->convertToSale($r, [
                        'payment_method' => 'efectivo',
                        'amount_paid' => (float) $r->total,
                    ]);
                    Notification::make()->title('Venta creada')->body("Se generó la venta {$sale->sale_number}.")->success()->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotes::route('/'),
            'create' => Pages\CreateQuote::route('/create'),
            'edit' => Pages\EditQuote::route('/{record}/edit'),
        ];
    }
}
