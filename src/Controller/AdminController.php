<?php declare(strict_types=1);

namespace IiifFromFile\Controller;

use Common\Stdlib\PsrMessage;
use IiifFromFile\Form\IiifFromFileForm;
use IiifFromFile\Job\ExportToRepository;
use IiifFromFile\Job\SyncToRepository;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class AdminController extends AbstractActionController
{
    public function indexAction()
    {
        $services = $this->getEvent()->getApplication()
            ->getServiceManager();
        $config = $services->get('Config');
        $endpoints = $config['iiiffromfile']['endpoints'] ?? [];

        $form = $services->get('FormElementManager')
            ->get(IiifFromFileForm::class);
        $form->setOption('endpoints', $endpoints);
        $form->init();

        $view = new ViewModel([
            'form' => $form,
        ]);

        if (!$this->getRequest()->isPost()) {
            return $view;
        }

        $post = $this->params()->fromPost();
        $form->setData($post);
        if (!$form->isValid()) {
            $this->messenger()->addError(
                'Invalid form data.' // @translate
            );
            return $view;
        }

        $data = $form->getData();
        $endpointKey = $data['endpoint'] ?? '';
        if (!isset($endpoints[$endpointKey])) {
            $this->messenger()->addError(
                'Unknown endpoint.' // @translate
            );
            return $view;
        }

        $query = [];
        parse_str($data['query'] ?? '', $query);
        unset($query['submit']);

        $params = [
            'endpoint' => $endpointKey,
            'endpoint_config' => $endpoints[$endpointKey],
            'collection' => $data['collection'] ?? '',
            'status' => $data['status'] ?? '',
            'other_params' => $data['other_params'] ?? [],
            'query' => $query,
            'metadata_mapping' => $data['metadata_mapping'] ?? [],
            'property_identifier' => $data['property_identifier'] ?? '',
            'property_url' => $data['property_url'] ?? '',
            'api_user' => $data['api_user'] ?? '',
            'api_key' => $data['api_key'] ?? '',
            'media_mode' => $data['media_mode'] ?? 'convert',
            'ingester' => $data['ingester'] ?? 'auto',
            'default_lang' => $data['default_lang'] ?? '',
            'sync_status' => $data['sync_status'] ?? '',
            'sync_mode' => $data['sync_mode'] ?? 'overwrite',
        ];

        $action = $data['action'] ?? 'export';
        $jobClass = $action === 'sync'
            ? SyncToRepository::class
            : ExportToRepository::class;

        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch($jobClass, $params);

        $urlPlugin = $this->url();
        $message = new PsrMessage(
            'Processing {action} in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'action' => $action === 'sync'
                    ? $this->translate('Sync') // @translate
                    : $this->translate('Export'), // @translate
                'link_job' => sprintf('<a href="%s">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', $urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                    : sprintf('<a href="%1$s" target="_blank">', $urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        return $this->redirect()->toRoute('admin/iiif-from-file');
    }
}
