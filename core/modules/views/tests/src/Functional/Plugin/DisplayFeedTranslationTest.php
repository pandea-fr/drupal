<?php

namespace Drupal\Tests\views\Functional\Plugin;

use Drupal\Tests\views\Functional\ViewTestBase;
use Drupal\language\Entity\ConfigurableLanguage;

/**
 * Tests the feed display plugin with translated content.
 *
 * @group views
 * @see \Drupal\views\Plugin\views\display\Feed
 */
class DisplayFeedTranslationTest extends ViewTestBase {

  /**
   * Views used by this test.
   *
   * @var array
   */
  public static $testViews = ['test_display_feed'];

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['node', 'views', 'language', 'content_translation'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The added languages.
   *
   * @var string[]
   */
  protected $langcodes;

  protected function setUp($import_test_views = TRUE) {
    parent::setUp($import_test_views);

    $this->enableViewsTestModule();

    $this->langcodes = ['es', 'pt-br'];
    foreach ($this->langcodes as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }

    $admin_user = $this->drupalCreateUser([
      'administer site configuration',
      'administer nodes',
      'administer content translation',
      'translate any entity',
      'create content translations',
      'administer languages',
    ]);
    $this->drupalLogin($admin_user);

    $this->drupalCreateContentType(['type' => 'page']);

    // Enable translation for page.
    $edit = [
      'entity_types[node]' => TRUE,
      'settings[node][page][translatable]' => TRUE,
      'settings[node][page][settings][language][language_alterable]' => TRUE,
    ];
    $this->drupalPostForm('admin/config/regional/content-language', $edit, t('Save configuration'));

    // Rebuild the container so that the new languages are picked up by services
    // that hold a list of languages.
    $this->rebuildContainer();
  }

  /**
   * Tests the rendered output for fields display with multiple translations.
   */
  public function testFeedFieldOutput() {
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'en',
      'body' => [
        0 => [
          'value' => 'Something in English.',
          'format' => filter_default_format(),
        ],
      ],
      'langcode' => 'en',
    ]);
    $es_translation = $node->addTranslation('es');
    $es_translation->set('title', 'es');
    $es_translation->set('body', [['value' => 'Algo en Español']]);
    $es_translation->save();

    $pt_br_translation = $node->addTranslation('pt-br');
    $pt_br_translation->set('title', 'pt-br');
    $pt_br_translation->set('body', [['value' => 'Algo em Português']]);
    $pt_br_translation->save();

    /** @var \Drupal\Core\Language\LanguageManagerInterface $languageManager */
    $language_manager = \Drupal::languageManager()->reset();

    $node_links = [];
    $node_links['en'] = $node->toUrl()->setAbsolute()->toString();
    foreach ($this->langcodes as $langcode) {
      $node_links[$langcode] = $node->toUrl()
        ->setOption('language', $language_manager->getLanguage($langcode))
        ->setAbsolute()
        ->toString();
    }

    $expected = [
      'pt-br' => [
        'description' => '<p>Algo em Português</p>',
      ],
      'es' => [
        'description' => '<p>Algo en Español</p>',
      ],
      'en' => [
        'description' => '<p>Something in English.</p>',
      ],
    ];
    foreach ($node_links as $langcode => $link) {
      $expected[$langcode]['link'] = $link;
    }

    $this->drupalGet('test-feed-display-fields.xml');
    $this->assertResponse(200);

    $items = $this->getSession()->getDriver()->find('//channel/item');
    // There should only be 3 items in the feed.
    $this->assertCount(3, $items);

    // Don't rely on the sort order of the items in the feed. Instead, each
    // item's title is the langcode for that item. Iterate over all the items,
    // get the title text for each one, make sure we're expecting each langcode
    // we find, and then assert that the rest of the content of that item is
    // what we expect for the given langcode.
    foreach ($items as $item) {
      // Sadly, the test view we're using uses links in the title.
      // @todo Fix the test view to not do this and update selectors.
      // @see https://www.drupal.org/project/drupal/issues/3092571
      $title_element = $item->findAll('xpath', 'title/a');
      $this->assertCount(1, $title_element);
      $langcode = $title_element[0]->getText();
      $this->assertArrayHasKey($langcode, $expected);
      foreach ($expected[$langcode] as $key => $expected_value) {
        $elements = $item->findAll('xpath', $key);
        $this->assertCount(1, $elements);
        $this->assertEquals($expected_value, $elements[0]->getText());
      }
    }
  }

}