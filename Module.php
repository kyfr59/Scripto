<?php
namespace Scripto;

use DateTime;
use Omeka\Module\AbstractModule;
use Scripto\Form\ConfigForm;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\Controller\AbstractController;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $this->addAclRules();
    }

    public function install(ServiceLocatorInterface $services)
    {
        $services->get('Omeka\Connection')->exec('
SET FOREIGN_KEY_CHECKS=0;
CREATE TABLE scripto_media (id INT AUTO_INCREMENT NOT NULL, scripto_item_id INT NOT NULL, media_id INT NOT NULL, approved_by_id INT DEFAULT NULL, completed_by VARCHAR(255) DEFAULT NULL, position INT NOT NULL, synced DATETIME NOT NULL, edited DATETIME DEFAULT NULL, completed DATETIME DEFAULT NULL, approved DATETIME DEFAULT NULL, INDEX IDX_28ABA038DE42D3B8 (scripto_item_id), INDEX IDX_28ABA038EA9FDD75 (media_id), INDEX IDX_28ABA0382D234F6A (approved_by_id), UNIQUE INDEX UNIQ_28ABA038DE42D3B8EA9FDD75 (scripto_item_id, media_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE scripto_item (id INT AUTO_INCREMENT NOT NULL, scripto_project_id INT NOT NULL, item_id INT NOT NULL, synced DATETIME NOT NULL, edited DATETIME DEFAULT NULL, INDEX IDX_2A827D37DC45463D (scripto_project_id), INDEX IDX_2A827D37126F525E (item_id), UNIQUE INDEX UNIQ_2A827D37DC45463D126F525E (scripto_project_id, item_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE scripto_project (id INT AUTO_INCREMENT NOT NULL, owner_id INT DEFAULT NULL, item_set_id INT DEFAULT NULL, property_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, created DATETIME NOT NULL, synced DATETIME DEFAULT NULL, imported DATETIME DEFAULT NULL, INDEX IDX_E39E51087E3C61F9 (owner_id), INDEX IDX_E39E5108960278D7 (item_set_id), INDEX IDX_E39E5108549213EC (property_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
ALTER TABLE scripto_media ADD CONSTRAINT FK_28ABA038DE42D3B8 FOREIGN KEY (scripto_item_id) REFERENCES scripto_item (id) ON DELETE CASCADE;
ALTER TABLE scripto_media ADD CONSTRAINT FK_28ABA038EA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE;
ALTER TABLE scripto_media ADD CONSTRAINT FK_28ABA0382D234F6A FOREIGN KEY (approved_by_id) REFERENCES user (id) ON DELETE SET NULL;
ALTER TABLE scripto_item ADD CONSTRAINT FK_2A827D37DC45463D FOREIGN KEY (scripto_project_id) REFERENCES scripto_project (id) ON DELETE CASCADE;
ALTER TABLE scripto_item ADD CONSTRAINT FK_2A827D37126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE;
ALTER TABLE scripto_project ADD CONSTRAINT FK_E39E51087E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE SET NULL;
ALTER TABLE scripto_project ADD CONSTRAINT FK_E39E5108960278D7 FOREIGN KEY (item_set_id) REFERENCES item_set (id) ON DELETE SET NULL;
ALTER TABLE scripto_project ADD CONSTRAINT FK_E39E5108549213EC FOREIGN KEY (property_id) REFERENCES property (id) ON DELETE SET NULL;
SET FOREIGN_KEY_CHECKS=1;
');
    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        $services->get('Omeka\Connection')->exec('
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS scripto_media;
DROP TABLE IF EXISTS scripto_item;
DROP TABLE IF EXISTS scripto_project;
SET FOREIGN_KEY_CHECKS=1;
');
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $settings->delete('scripto_apiurl');
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $form = $this->getServiceLocator()->get('FormElementManager')->get('Scripto\Form\ConfigForm');
        $form->init();
        $form->setData([
            'apiurl' => $settings->get('scripto_apiurl'),
        ]);
        return $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $form = $this->getServiceLocator()->get('FormElementManager')->get('Scripto\Form\ConfigForm');
        $form->init();
        $form->setData($controller->params()->fromPost());
        if ($form->isValid()) {
            $formData = $form->getData();
            $settings->set('scripto_apiurl', $formData['apiurl']);
            return true;
        }
        $controller->messenger()->addErrors($form->getMessages());
        return false;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            '*',
            'sql_filter.resource_visibility',
            function (Event $event) {
                // Users can view Scripto items and Scripto media only if they
                // have permission to view the related Omeka item or Omeka media.
                $relatedEntities = $event->getParam('relatedEntities');
                $relatedEntities['Scripto\Entity\ScriptoItem'] = 'item_id';
                $relatedEntities['Scripto\Entity\ScriptoMedia'] = 'media_id';
                $event->setParam('relatedEntities', $relatedEntities);
            }
        );
        $sharedEventManager->attach(
            'Scripto\Entity\ScriptoProject',
            'entity.persist.post',
            [$this, 'addItemsToNewProjects']
        );
        $sharedEventManager->attach(
            'Scripto\Api\Adapter\ScriptoMediaAdapter',
            'api.hydrate.post',
            [$this, 'editMediawikiPage']
        );
    }

    /**
     * Add ACL rules for this module.
     */
    protected function addAclRules()
    {
        // Everyone has general access to the Scripto resources.
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(
            null,
            [
                'Scripto\Api\Adapter\ScriptoProjectAdapter',
                'Scripto\Api\Adapter\ScriptoItemAdapter',
                'Scripto\Api\Adapter\ScriptoMediaAdapter',
            ]
        );
        // Everyone can read the Scripto entities.
        $acl->allow(
            null,
            [
                'Scripto\Entity\ScriptoProject',
                'Scripto\Entity\ScriptoItem',
                'Scripto\Entity\ScriptoMedia',
            ],
            'read'
        );
    }


    /**
     * Add all items from the corresponding item set to newly created projects.
     *
     * @param Event $event
     */
    public function addItemsToNewProjects(Event $event)
    {
        $project = $event->getTarget();
        $dispatcher = $this->getServiceLocator()->get('Omeka\Job\Dispatcher');
        $dispatcher->dispatch('Scripto\Job\SyncProject', [
            'scripto_project_id' => $project->getId(),
        ]);
    }

    /**
     * Create or edit a MediaWiki page given a Scripto media entity.
     *
     * Attaches to the api.hydrate.post event to ensure that the corresponding
     * MediaWiki page is successfully created/edited prior to updating the
     * Scripto media entity. Ideally we'd use entity.update.pre to ensure that
     * the entity is validated, but it isn't triggered when there are no changes
     * to the entity (i.e. when only the text has changed).
     *
     * @param Event $event
     */
    public function editMediawikiPage(Event $event)
    {
        $sMedia = $event->getParam('entity');
        if (!is_string($sMedia->getText())) {
            // No need to edit the MediaWiki page if text is null.
            return;
        }

        $client = $this->getServiceLocator()->get('Scripto\Mediawiki\ApiClient');
        $translator = $this->getServiceLocator()->get('MvcTranslator');

        $pageTitle = $sMedia->getMediawikiPageTitle();
        $page = $client->queryPage($pageTitle);
        $pageIsCreated = $client->pageIsCreated($page);

        if (!$pageIsCreated && !$client->userCan($page, 'createpage')) {
            throw new \Exception(sprintf(
                $translator->translate('The MediaWiki user does not have the necessary permissions to create the page "%s"'),
                $pageTitle
            ));
        }
        if ($pageIsCreated && !$client->userCan($page, 'edit')) {
            throw new \Exception(sprintf(
                $translator->translate('The MediaWiki user does not have the necessary permissions to edit the page "%s"'),
                $pageTitle
            ));
        }

        $result = $client->editPage($pageTitle, $sMedia->getText());

        if (!isset($result['nochange'])) {
            // Update edited datetime only if there was a change.
            $now = new DateTime('now');
            $sMedia->setEdited($now);
            $sMedia->getScriptoItem()->setEdited($now);
        }
    }
}
