<?php

namespace Tests\Feature;

use App\Support\CodeSequenceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CodeSequenceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_code_of_a_type_starts_at_one_with_current_year(): void
    {
        $code = CodeSequenceGenerator::next('commande', 'ZN');
        $anneeCourte = now()->format('y');

        $this->assertSame("ZN-{$anneeCourte}001", $code);
    }

    public function test_codes_increment_sequentially_within_the_same_type(): void
    {
        $premier = CodeSequenceGenerator::next('commande', 'ZN');
        $second = CodeSequenceGenerator::next('commande', 'ZN');
        $troisieme = CodeSequenceGenerator::next('commande', 'ZN');
        $anneeCourte = now()->format('y');

        $this->assertSame("ZN-{$anneeCourte}001", $premier);
        $this->assertSame("ZN-{$anneeCourte}002", $second);
        $this->assertSame("ZN-{$anneeCourte}003", $troisieme);
    }

    public function test_each_type_has_its_own_independent_counter(): void
    {
        CodeSequenceGenerator::next('commande', 'ZN');
        CodeSequenceGenerator::next('commande', 'ZN');
        $litige = CodeSequenceGenerator::next('litige', 'LIT');
        $anneeCourte = now()->format('y');

        // Le compteur "litige" démarre à 1 malgré les 2 appels déjà faits pour "commande".
        $this->assertSame("LIT-{$anneeCourte}001", $litige);
    }

    public function test_number_grows_beyond_the_minimum_digit_count_without_truncation(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $code = CodeSequenceGenerator::next('ticket', 'TICK');
        }
        $anneeCourte = now()->format('y');

        $this->assertSame("TICK-{$anneeCourte}1000", $code);
    }

    public function test_generated_commande_and_litige_and_ticket_numbers_use_the_new_format(): void
    {
        $this->assertMatchesRegularExpression('/^ZN-\d{5,}$/', CodeSequenceGenerator::next('commande', 'ZN'));
        $this->assertMatchesRegularExpression('/^LIT-\d{5,}$/', \App\Models\Litige::genererNumero());
        $this->assertMatchesRegularExpression('/^TICK-\d{5,}$/', \App\Models\TicketSupport::genererNumero());
    }
}
