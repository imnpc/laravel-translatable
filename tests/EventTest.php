<?php

namespace SolutionForest\Translatable\Test;

use SolutionForest\Translatable\Events\TranslationHasBeenSet;

class EventTest extends TestCase
{
    /** @var \SolutionForest\Translatable\Test\TestModel */
    protected $testModel;

    public function setUp(): void
    {
        parent::setUp();

        $this->testModel = new TestModel();
    }

    /** @test */
    public function it_will_fire_an_event_when_a_translation_has_been_set()
    {
        $this->expectsEvents(TranslationHasBeenSet::class);

        $this->testModel->setTranslation('name', 'en', 'testValue_en');
    }
}
