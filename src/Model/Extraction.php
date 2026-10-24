<?php

declare(strict_types=1);

namespace Viz\Model;

/** Wynik wyciągania ścieżek z jednego wywołania narzędzia. */
final class Extraction
{
    /** @var list<Touch> */
    public array $touches = [];
    /** @var list<string> fragmenty komend, z których nie dało się ustalić ścieżki */
    public array $unresolved = [];
    /** @var list<string> programy, które same czytają pliki (composer, php skrypt, python -) */
    public array $indirect = [];
    /** @var list<string> komendy spoza reguł parsera */
    public array $unknown = [];
    /** @var list<Command> wystąpienia programów (M11) */
    public array $commands = [];
    /** @var array<int, string> ostatni program na danym poziomie zagnieżdżenia — do par w potoku */
    public array $lastByDepth = [];

    public function __construct(public ?string $cwdAfter = null)
    {
    }

    public function add(Touch $touch): void
    {
        $this->touches[] = $touch;
    }
}
