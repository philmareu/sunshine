<?php

namespace App\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Passport\Client;
use Laravel\Passport\Token;

/**
 * Connects Claude to Sunshine's MCP server (routes/ai.php). Claude signs in with OAuth: the user logs in to Sunshine
 * and approves it on the consent screen. Lists the apps the user has approved, each of which can be disconnected.
 */
class Claude extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?string $navigationLabel = 'Claude';

    protected static ?string $title = 'Connect Claude';

    protected static ?int $navigationSort = 8;

    public function getSubheading(): string
    {
        return __('Claude can tell you what you have planned and what your scans found, read your preferences, check, add and remove the pages you watch, and start and read ad hoc searches, as you.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Connect Claude'))
                    ->description(__('You\'ll sign in to Sunshine and approve Claude. It never sees your password.'))
                    ->schema([
                        TextEntry::make('serverUrl')
                            ->label(__('Server URL'))
                            ->state(fn (): string => route('mcp'))
                            ->helperText(__('For Claude on the web, desktop or phone: add a custom connector in Claude\'s settings and paste this URL.'))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                        TextEntry::make('claudeCodeCommand')
                            ->label(__('Claude Code'))
                            ->state(fn (): string => sprintf('claude mcp add --transport http --scope user sunshine %s', route('mcp')))
                            ->helperText(__('Run this in a terminal, then use /mcp in a Claude Code session to sign in.'))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                    ]),
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Connected apps'))
            ->query(fn (): Builder => Client::query()
                ->whereHas('tokens', fn (Builder $tokens): Builder => $tokens
                    ->where('user_id', $this->user()->getKey())
                    ->where('revoked', false))
                ->withMin(['tokens as connected_at' => fn (Builder $tokens): Builder => $tokens
                    ->where('user_id', $this->user()->getKey())], 'created_at'))
            ->defaultSort('name')
            ->paginated(false)
            ->emptyStateHeading(__('No connected apps'))
            ->emptyStateDescription(__('Apps you approve will appear here.'))
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('redirect')
                    ->label(__('Domain'))
                    ->state(fn (Client $record): ?string => parse_url($record->redirect_uris[0] ?? '', PHP_URL_HOST) ?: null),
                TextColumn::make('connected_at')
                    ->label(__('Connected'))
                    ->date(),
            ])
            ->recordActions([
                Action::make('disconnect')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('This app will lose access to Sunshine until you approve it again.'))
                    ->action(function (Client $record): void {
                        $this->disconnect($record);

                        Notification::make()
                            ->title(__('App disconnected'))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * Revoke the signed-in user's access for an app. Clients are shared between users, so the client itself stays.
     */
    protected function disconnect(Client $client): void
    {
        $user = $this->user();

        $user->tokens()
            ->where('client_id', $client->getKey())
            ->where('revoked', false)
            ->with('refreshToken')
            ->each(function (Token $token): void {
                $token->revoke();
                $token->refreshToken?->revoke();
            });

        $client->authCodes()
            ->where('user_id', $user->getKey())
            ->update(['revoked' => true]);
    }

    protected function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
