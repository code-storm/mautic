<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\LeadBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Helper\EmojiHelper;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LeadController extends FormController
{
    use LeadDetailsTrait, FrequencyRuleTrait;

    /**
     * @param int $page
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction($page = 1)
    {
        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'lead:leads:viewown',
                'lead:leads:viewother',
                'lead:leads:create',
                'lead:leads:editown',
                'lead:leads:editother',
                'lead:leads:deleteown',
                'lead:leads:deleteother',
            ],
            'RETURN_ARRAY'
        );

        if (!$permissions['lead:leads:viewown'] && !$permissions['lead:leads:viewother']) {
            return $this->accessDenied();
        }

        if ($this->request->getMethod() == 'POST') {
            $this->setListFilters();
        }

        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model   = $this->getModel('lead');
        $session = $this->get('session');
        //set limits
        $limit = $session->get('mautic.lead.limit', $this->get('mautic.helper.core_parameters')->getParameter('default_pagelimit'));
        $start = ($page === 1) ? 0 : (($page - 1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        $search = $this->request->get('search', $session->get('mautic.lead.filter', 'is:anonymous'));
        $session->set('mautic.lead.filter', $search);

        //do some default filtering
        $orderBy    = $session->get('mautic.lead.orderby', 'l.last_active');
        $orderByDir = $session->get('mautic.lead.orderbydir', 'DESC');

        $filter      = ['string' => $search, 'force' => ''];
        $translator  = $this->get('translator');
        $anonymous   = $translator->trans('mautic.lead.lead.searchcommand.isanonymous');
        $listCommand = $translator->trans('mautic.lead.lead.searchcommand.list');
        $mine        = $translator->trans('mautic.core.searchcommand.ismine');
        $indexMode   = $this->request->get('view', $session->get('mautic.lead.indexmode', 'list'));

        $session->set('mautic.lead.indexmode', $indexMode);

        $anonymousShowing = false;
        if ($indexMode != 'list' || ($indexMode == 'list' && strpos($search, $anonymous) === false)) {
            //remove anonymous leads unless requested to prevent clutter
            $filter['force'] .= " !$anonymous";
        } elseif (strpos($search, $anonymous) !== false && strpos($search, '!'.$anonymous) === false) {
            $anonymousShowing = true;
        }

        if (!$permissions['lead:leads:viewother']) {
            $filter['force'] .= " $mine";
        }

        $results = $model->getEntities([
            'start'          => $start,
            'limit'          => $limit,
            'filter'         => $filter,
            'orderBy'        => $orderBy,
            'orderByDir'     => $orderByDir,
            'withTotalCount' => true,
        ]);

        $count = $results['count'];
        unset($results['count']);

        $leads = $results['results'];
        unset($results);

        if ($count && $count < ($start + 1)) {
            //the number of entities are now less then the current page so redirect to the last page
            if ($count === 1) {
                $lastPage = 1;
            } else {
                $lastPage = (ceil($count / $limit)) ?: 1;
            }
            $session->set('mautic.lead.page', $lastPage);
            $returnUrl = $this->generateUrl('mautic_contact_index', ['page' => $lastPage]);

            return $this->postActionRedirect(
                [
                    'returnUrl'       => $returnUrl,
                    'viewParameters'  => ['page' => $lastPage],
                    'contentTemplate' => 'MauticLeadBundle:Lead:index',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_contact_index',
                        'mauticContent' => 'lead',
                    ],
                ]
            );
        }

        //set what page currently on so that we can return here after form submission/cancellation
        $session->set('mautic.lead.page', $page);

        $tmpl = $this->request->isXmlHttpRequest() ? $this->request->get('tmpl', 'index') : 'index';

        $listArgs = [];
        if (!$this->get('mautic.security')->isGranted('lead:lists:viewother')) {
            $listArgs['filter']['force'] = " $mine";
        }

        $lists = $this->getModel('lead.list')->getUserLists();

        //check to see if in a single list
        $inSingleList = (substr_count($search, "$listCommand:") === 1) ? true : false;
        $list         = [];
        if ($inSingleList) {
            preg_match("/$listCommand:(.*?)(?=\s|$)/", $search, $matches);

            if (!empty($matches[1])) {
                $alias = $matches[1];
                foreach ($lists as $l) {
                    if ($alias === $l['alias']) {
                        $list = $l;
                        break;
                    }
                }
            }
        }

        // Get the max ID of the latest lead added
        $maxLeadId = $model->getRepository()->getMaxLeadId();

        // We need the EmailRepository to check if a lead is flagged as do not contact
        /** @var \Mautic\EmailBundle\Entity\EmailRepository $emailRepo */
        $emailRepo = $this->getModel('email')->getRepository();

        return $this->delegateView(
            [
                'viewParameters' => [
                    'searchValue'      => $search,
                    'items'            => $leads,
                    'page'             => $page,
                    'totalItems'       => $count,
                    'limit'            => $limit,
                    'permissions'      => $permissions,
                    'tmpl'             => $tmpl,
                    'indexMode'        => $indexMode,
                    'lists'            => $lists,
                    'currentList'      => $list,
                    'security'         => $this->get('mautic.security'),
                    'inSingleList'     => $inSingleList,
                    'noContactList'    => $emailRepo->getDoNotEmailList(),
                    'maxLeadId'        => $maxLeadId,
                    'anonymousShowing' => $anonymousShowing,
                ],
                'contentTemplate' => "MauticLeadBundle:Lead:{$indexMode}.html.php",
                'passthroughVars' => [
                    'activeLink'    => '#mautic_contact_index',
                    'mauticContent' => 'lead',
                    'route'         => $this->generateUrl('mautic_contact_index', ['page' => $page]),
                ],
            ]
        );
    }

    /*
     * Quick form controller route and view
     */
    public function quickAddAction()
    {
        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model = $this->getModel('lead.lead');

        // Get the quick add form
        $action = $this->generateUrl('mautic_contact_action', ['objectAction' => 'new', 'qf' => 1]);

        $fields = $this->getModel('lead.field')->getEntities(
            [
                'filter' => [
                    'force' => [
                        [
                            'column' => 'f.isPublished',
                            'expr'   => 'eq',
                            'value'  => true,
                        ],
                        [
                            'column' => 'f.isShortVisible',
                            'expr'   => 'eq',
                            'value'  => true,
                        ],
                        [
                            'column' => 'f.object',
                            'expr'   => 'like',
                            'value'  => 'lead',
                        ],
                    ],
                ],
                'hydration_mode' => 'HYDRATE_ARRAY',
            ]
        );

        $quickForm = $model->createForm($model->getEntity(), $this->get('form.factory'), $action, ['fields' => $fields, 'isShortForm' => true]);

        //set the default owner to the currently logged in user
        $currentUser = $this->get('security.context')->getToken()->getUser();
        $quickForm->get('owner')->setData($currentUser);

        return $this->delegateView(
            [
                'viewParameters' => [
                    'quickForm' => $quickForm->createView(),
                ],
                'contentTemplate' => 'MauticLeadBundle:Lead:quickadd.html.php',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_contact_index',
                    'mauticContent' => 'lead',
                    'route'         => false,
                ],
            ]
        );
    }

    /**
     * Loads a specific lead into the detailed panel.
     *
     * @param $objectId
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function viewAction($objectId)
    {
        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model = $this->getModel('lead.lead');

        /** @var \Mautic\LeadBundle\Entity\Lead $lead */
        $lead = $model->getEntity($objectId);

        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'lead:leads:viewown',
                'lead:leads:viewother',
                'lead:leads:create',
                'lead:leads:editown',
                'lead:leads:editother',
                'lead:leads:deleteown',
                'lead:leads:deleteother',
            ],
            'RETURN_ARRAY'
        );

        if ($lead === null) {
            //get the page we came from
            $page = $this->get('session')->get('mautic.lead.page', 1);

            //set the return URL
            $returnUrl = $this->generateUrl('mautic_contact_index', ['page' => $page]);

            return $this->postActionRedirect(
                [
                    'returnUrl'       => $returnUrl,
                    'viewParameters'  => ['page' => $page],
                    'contentTemplate' => 'MauticLeadBundle:Lead:index',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_contact_index',
                        'mauticContent' => 'contact',
                    ],
                    'flashes' => [
                        [
                            'type'    => 'error',
                            'msg'     => 'mautic.lead.lead.error.notfound',
                            'msgVars' => ['%id%' => $objectId],
                        ],
                    ],
                ]
            );
        }

        if (!$this->get('mautic.security')->hasEntityAccess(
            'lead:leads:viewown',
            'lead:leads:viewother',
            $lead->getPermissionUser()
        )
        ) {
            return $this->accessDenied();
        }

        $fields            = $lead->getFields();
        $integrationHelper = $this->get('mautic.helper.integration');
        $socialProfiles    = (array) $integrationHelper->getUserProfiles($lead, $fields);
        $socialProfileUrls = $integrationHelper->getSocialProfileUrlRegex(false);
        /** @var \Mautic\LeadBundle\Model\CompanyModel $model */
        $companyModel  = $this->getModel('lead.company');
        $companiesRepo = $companyModel->getRepository();
        $companies     = $companiesRepo->getCompaniesByLeadId($objectId);
        // Set the social profile templates
        if ($socialProfiles) {
            foreach ($socialProfiles as $integration => &$details) {
                if ($integrationObject = $integrationHelper->getIntegrationObject($integration)) {
                    if ($template = $integrationObject->getSocialProfileTemplate()) {
                        $details['social_profile_template'] = $template;
                    }
                }

                if (!isset($details['social_profile_template'])) {
                    // No profile template found
                    unset($socialProfiles[$integration]);
                }
            }
        }

        // We need the EmailRepository to check if a lead is flagged as do not contact
        /** @var \Mautic\EmailBundle\Entity\EmailRepository $emailRepo */
        $emailRepo = $this->getModel('email')->getRepository();

        return $this->delegateView(
            [
                'viewParameters' => [
                    'lead'              => $lead,
                    'avatarPanelState'  => $this->request->cookies->get('mautic_lead_avatar_panel', 'expanded'),
                    'fields'            => $fields,
                    'companies'         => $companies,
                    'socialProfiles'    => $socialProfiles,
                    'socialProfileUrls' => $socialProfileUrls,
                    'places'            => $this->getPlaces($lead),
                    'permissions'       => $permissions,
                    'events'            => $this->getEngagements($lead),
                    'upcomingEvents'    => $this->getScheduledCampaignEvents($lead),
                    'engagementData'    => $this->getEngagementData($lead),
                    'noteCount'         => $this->getModel('lead.note')->getNoteCount($lead, true),
                    'doNotContact'      => $emailRepo->checkDoNotEmail($fields['core']['email']['value']),
                    'leadNotes'         => $this->forward(
                        'MauticLeadBundle:Note:index',
                        [
                            'leadId'     => $lead->getId(),
                            'ignoreAjax' => 1,
                        ]
                    )->getContent(),
                ],
                'contentTemplate' => 'MauticLeadBundle:Lead:lead.html.php',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_contact_index',
                    'mauticContent' => 'lead',
                    'route'         => $this->generateUrl(
                        'mautic_contact_action',
                        [
                            'objectAction' => 'view',
                            'objectId'     => $lead->getId(),
                        ]
                    ),
                ],
            ]
        );
    }

    /**
     * Generates new form and processes post data.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function newAction()
    {
        /** @var LeadModel $model */
        $model = $this->getModel('lead.lead');
        $lead  = $model->getEntity();

        if (!$this->get('mautic.security')->isGranted('lead:leads:create')) {
            return $this->accessDenied();
        }

        //set the page we came from
        $page = $this->get('session')->get('mautic.lead.page', 1);

        $action = $this->generateUrl('mautic_contact_action', ['objectAction' => 'new']);
        $fields = $this->getModel('lead.field')->getPublishedFieldArrays('lead');

        $form = $model->createForm($lead, $this->get('form.factory'), $action, ['fields' => $fields]);

        ///Check for a submitted form and process it
        if ($this->request->getMethod() == 'POST') {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    //get custom field values
                    $data = $this->request->request->get('lead');

                    //pull the data from the form in order to apply the form's formatting
                    foreach ($form as $f) {
                        if ('companies' !== $f->getName()) {
                            $data[$f->getName()] = $f->getData();
                        }
                    }

                    $companies = [];
                    if (isset($data['companies'])) {
                        $companies = $data['companies'];
                        unset($data['companies']);
                    }

                    $model->setFieldValues($lead, $data, true);

                    //form is valid so process the data
                    $model->saveEntity($lead);

                    if (!empty($companies)) {
                        $model->modifyCompanies($lead, $companies);
                    }

                    // Upload avatar if applicable
                    $image = $form['preferred_profile_image']->getData();
                    if ($image == 'custom') {
                        // Check for a file
                        /** @var UploadedFile $file */
                        if ($file = $form['custom_avatar']->getData()) {
                            $this->uploadAvatar($lead);
                        }
                    }

                    $identifier = $this->get('translator')->trans($lead->getPrimaryIdentifier());

                    $this->addFlash(
                        'mautic.core.notice.created',
                        [
                            '%name%'      => $identifier,
                            '%menu_link%' => 'mautic_contact_index',
                            '%url%'       => $this->generateUrl(
                                'mautic_contact_action',
                                [
                                    'objectAction' => 'edit',
                                    'objectId'     => $lead->getId(),
                                ]
                            ),
                        ]
                    );

                    $inQuickForm = $this->request->get('qf', false);

                    if ($inQuickForm) {
                        $viewParameters = ['page' => $page];
                        $returnUrl      = $this->generateUrl('mautic_contact_index', $viewParameters);
                        $template       = 'MauticLeadBundle:Lead:index';
                    } elseif ($form->get('buttons')->get('save')->isClicked()) {
                        $viewParameters = [
                            'objectAction' => 'view',
                            'objectId'     => $lead->getId(),
                        ];
                        $returnUrl = $this->generateUrl('mautic_contact_action', $viewParameters);
                        $template  = 'MauticLeadBundle:Lead:view';
                    } else {
                        return $this->editAction($lead->getId(), true);
                    }
                }
            } else {
                $viewParameters = ['page' => $page];
                $returnUrl      = $this->generateUrl('mautic_contact_index', $viewParameters);
                $template       = 'MauticLeadBundle:Lead:index';
            }

            if ($cancelled || $valid) { //cancelled or success
                return $this->postActionRedirect(
                    [
                        'returnUrl'       => $returnUrl,
                        'viewParameters'  => $viewParameters,
                        'contentTemplate' => $template,
                        'passthroughVars' => [
                            'activeLink'    => '#mautic_contact_index',
                            'mauticContent' => 'lead',
                            'closeModal'    => 1, //just in case in quick form
                        ],
                    ]
                );
            }
        } else {
            //set the default owner to the currently logged in user
            $currentUser = $this->get('security.context')->getToken()->getUser();
            $form->get('owner')->setData($currentUser);
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'form'   => $form->createView(),
                    'lead'   => $lead,
                    'fields' => $model->organizeFieldsByGroup($fields),
                ],
                'contentTemplate' => 'MauticLeadBundle:Lead:form.html.php',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_contact_index',
                    'mauticContent' => 'lead',
                    'route'         => $this->generateUrl(
                        'mautic_contact_action',
                        [
                            'objectAction' => 'new',
                        ]
                    ),
                ],
            ]
        );
    }

    /**
     * Generates edit form.
     *
     * @param            $objectId
     * @param bool|false $ignorePost
     *
     * @return array|JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function editAction($objectId, $ignorePost = false)
    {
        /** @var LeadModel $model */
        $model = $this->getModel('lead.lead');
        $lead  = $model->getEntity($objectId);

        //set the page we came from
        $page = $this->get('session')->get('mautic.lead.page', 1);

        //set the return URL
        $returnUrl = $this->generateUrl('mautic_contact_index', ['page' => $page]);

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'MauticLeadBundle:Lead:index',
            'passthroughVars' => [
                'activeLink'    => '#mautic_contact_index',
                'mauticContent' => 'lead',
            ],
        ];
        //lead not found
        if ($lead === null) {
            return $this->postActionRedirect(
                array_merge(
                    $postActionVars,
                    [
                        'flashes' => [
                            [
                                'type'    => 'error',
                                'msg'     => 'mautic.lead.lead.error.notfound',
                                'msgVars' => ['%id%' => $objectId],
                            ],
                        ],
                    ]
                )
            );
        } elseif (!$this->get('mautic.security')->hasEntityAccess(
            'lead:leads:editown',
            'lead:leads:editother',
            $lead->getPermissionUser()
        )
        ) {
            return $this->accessDenied();
        } elseif ($model->isLocked($lead)) {
            //deny access if the entity is locked
            return $this->isLocked($postActionVars, $lead, 'lead.lead');
        }

        $action = $this->generateUrl('mautic_contact_action', ['objectAction' => 'edit', 'objectId' => $objectId]);
        $fields = $this->getModel('lead.field')->getPublishedFieldArrays('lead');
        $form   = $model->createForm($lead, $this->get('form.factory'), $action, ['fields' => $fields]);

        ///Check for a submitted form and process it
        if (!$ignorePost && $this->request->getMethod() == 'POST') {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $data = $this->request->request->get('lead');

                    //pull the data from the form in order to apply the form's formatting
                    foreach ($form as $f) {
                        if (('companies' !== $f->getName()) && ('company' !== $f->getName())) {
                            $data[$f->getName()] = $f->getData();
                        }
                    }

                    $companies = [];
                    if (isset($data['companies'])) {
                        $companies = $data['companies'];
                        unset($data['companies']);
                    }
                    $model->setFieldValues($lead, $data, true);

                    //form is valid so process the data
                    $model->saveEntity($lead, $form->get('buttons')->get('save')->isClicked());
                    $model->modifyCompanies($lead, $companies);

                    // Upload avatar if applicable
                    $image = $form['preferred_profile_image']->getData();
                    if ($image == 'custom') {
                        // Check for a file
                        /** @var UploadedFile $file */
                        if ($file = $form['custom_avatar']->getData()) {
                            $this->uploadAvatar($lead);

                            // Note the avatar update so that it can be forced to update
                            $this->get('session')->set('mautic.lead.avatar.updated', true);
                        }
                    }

                    $identifier = $this->get('translator')->trans($lead->getPrimaryIdentifier());

                    $this->addFlash(
                        'mautic.core.notice.updated',
                        [
                            '%name%'      => $identifier,
                            '%menu_link%' => 'mautic_contact_index',
                            '%url%'       => $this->generateUrl(
                                'mautic_contact_action',
                                [
                                    'objectAction' => 'edit',
                                    'objectId'     => $lead->getId(),
                                ]
                            ),
                        ]
                    );
                }
            } else {
                //unlock the entity
                $model->unlockEntity($lead);
            }

            if ($cancelled || ($valid && $form->get('buttons')->get('save')->isClicked())) {
                $viewParameters = [
                    'objectAction' => 'view',
                    'objectId'     => $lead->getId(),
                ];

                return $this->postActionRedirect(
                    array_merge(
                        $postActionVars,
                        [
                            'returnUrl'       => $this->generateUrl('mautic_contact_action', $viewParameters),
                            'viewParameters'  => $viewParameters,
                            'contentTemplate' => 'MauticLeadBundle:Lead:view',
                        ]
                    )
                );
            } elseif ($valid) {
                // Refetch and recreate the form in order to populate data manipulated in the entity itself
                $lead = $model->getEntity($objectId);
                $form = $model->createForm($lead, $this->get('form.factory'), $action, ['fields' => $fields]);
            }
        } else {
            //lock the entity
            $model->lockEntity($lead);
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'form'   => $form->createView(),
                    'lead'   => $lead,
                    'fields' => $lead->getFields(), //pass in the lead fields as they are already organized by ['group']['alias']
                ],
                'contentTemplate' => 'MauticLeadBundle:Lead:form.html.php',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_contact_index',
                    'mauticContent' => 'lead',
                    'route'         => $this->generateUrl(
                        'mautic_contact_action',
                        [
                            'objectAction' => 'edit',
                            'objectId'     => $lead->getId(),
                        ]
                    ),
                ],
            ]
        );
    }

    /**
     * Upload an asset.
     *
     * @param Lead $lead
     */
    private function uploadAvatar(Lead $lead)
    {
        $file      = $this->request->files->get('lead[custom_avatar]', null, true);
        $avatarDir = $this->get('mautic.helper.template.avatar')->getAvatarPath(true);

        if (!file_exists($avatarDir)) {
            mkdir($avatarDir);
        }

        $file->move($avatarDir, 'avatar'.$lead->getId());

        //remove the file from request
        $this->request->files->remove('lead');
    }

    /**
     * Generates merge form and action.
     *
     * @param   $objectId
     *
     * @return array|JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function mergeAction($objectId)
    {
        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model    = $this->getModel('lead');
        $mainLead = $model->getEntity($objectId);
        $page     = $this->get('session')->get('mautic.lead.page', 1);

        //set the return URL
        $returnUrl = $this->generateUrl('mautic_contact_index', ['page' => $page]);

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'MauticLeadBundle:Lead:index',
            'passthroughVars' => [
                'activeLink'    => '#mautic_contact_index',
                'mauticContent' => 'lead',
            ],
        ];

        if ($mainLead === null) {
            return $this->postActionRedirect(
                array_merge(
                    $postActionVars,
                    [
                        'flashes' => [
                            [
                                'type'    => 'error',
                                'msg'     => 'mautic.lead.lead.error.notfound',
                                'msgVars' => ['%id%' => $objectId],
                            ],
                        ],
                    ]
                )
            );
        }

        //do some default filtering
        $session = $this->get('session');
        $search  = $this->request->get('search', $session->get('mautic.lead.merge.filter', ''));
        $session->set('mautic.lead.merge.filter', $search);
        $leads = [];

        if (!empty($search)) {
            $filter = [
                'string' => $search,
                'force'  => [
                    [
                        'column' => 'l.date_identified',
                        'expr'   => 'isNotNull',
                        'value'  => $mainLead->getId(),
                    ],
                    [
                        'column' => 'l.id',
                        'expr'   => 'neq',
                        'value'  => $mainLead->getId(),
                    ],
                ],
            ];

            $leads = $model->getEntities(
                [
                    'limit'          => 25,
                    'filter'         => $filter,
                    'orderBy'        => 'l.firstname,l.lastname,l.company,l.email',
                    'orderByDir'     => 'ASC',
                    'withTotalCount' => false,
                ]
            );
        }

        $leadChoices = [];
        foreach ($leads as $l) {
            $leadChoices[$l->getId()] = $l->getPrimaryIdentifier();
        }

        $action = $this->generateUrl('mautic_contact_action', ['objectAction' => 'merge', 'objectId' => $mainLead->getId()]);

        $form = $this->get('form.factory')->create(
            'lead_merge',
            [],
            [
                'action' => $action,
                'leads'  => $leadChoices,
            ]
        );

        if ($this->request->getMethod() == 'POST') {
            $valid = true;
            if (!$this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $data      = $form->getData();
                    $secLeadId = $data['lead_to_merge'];
                    $secLead   = $model->getEntity($secLeadId);

                    if ($secLead === null) {
                        return $this->postActionRedirect(
                            array_merge(
                                $postActionVars,
                                [
                                    'flashes' => [
                                        [
                                            'type'    => 'error',
                                            'msg'     => 'mautic.lead.lead.error.notfound',
                                            'msgVars' => ['%id%' => $secLead->getId()],
                                        ],
                                    ],
                                ]
                            )
                        );
                    } elseif (
                        !$this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $mainLead->getPermissionUser())
                        || !$this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $secLead->getPermissionUser())
                    ) {
                        return $this->accessDenied();
                    } elseif ($model->isLocked($mainLead)) {
                        //deny access if the entity is locked
                        return $this->isLocked($postActionVars, $secLead, 'lead');
                    } elseif ($model->isLocked($secLead)) {
                        //deny access if the entity is locked
                        return $this->isLocked($postActionVars, $secLead, 'lead');
                    }

                    //Both leads are good so now we merge them
                    $mainLead = $model->mergeLeads($mainLead, $secLead, false);
                }
            }

            if ($valid) {
                $viewParameters = [
                    'objectId'     => $mainLead->getId(),
                    'objectAction' => 'view',
                ];

                return $this->postActionRedirect(
                    [
                        'returnUrl'       => $this->generateUrl('mautic_contact_action', $viewParameters),
                        'viewParameters'  => $viewParameters,
                        'contentTemplate' => 'MauticLeadBundle:Lead:view',
                        'passthroughVars' => [
                            'closeModal' => 1,
                        ],
                    ]
                );
            }
        }

        $tmpl = $this->request->get('tmpl', 'index');

        return $this->delegateView(
            [
                'viewParameters' => [
                    'tmpl'         => $tmpl,
                    'leads'        => $leads,
                    'searchValue'  => $search,
                    'action'       => $action,
                    'form'         => $form->createView(),
                    'currentRoute' => $this->generateUrl(
                        'mautic_contact_action',
                        [
                            'objectAction' => 'merge',
                            'objectId'     => $mainLead->getId(),
                        ]
                    ),
                ],
                'contentTemplate' => 'MauticLeadBundle:Lead:merge.html.php',
                'passthroughVars' => [
                    'route'  => false,
                    'target' => ($tmpl == 'update') ? '.lead-merge-options' : null,
                ],
            ]
        );
    }

    /**
     * Generates contact frequency rules form and action.
     *
     * @param   $objectId
     *
     * @return array|JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function contactFrequencyAction($objectId)
    {
        /** @var LeadModel $model */
        $model = $this->getModel('lead');
        $lead  = $model->getEntity($objectId);

        if ($lead === null
            || !$this->get('mautic.security')->hasEntityAccess(
                'lead:leads:editown',
                'lead:leads:editother',
                $lead->getPermissionUser()
            )
        ) {
            return $this->accessDenied();
        }

        $viewParameters = [
            'objectId'     => $lead->getId(),
            'objectAction' => 'view',
        ];

        $form = $this->getFrequencyRuleForm(
            $lead,
            $viewParameters,
            $data,
            false,
            $this->generateUrl('mautic_contact_action', ['objectAction' => 'contactFrequency', 'objectId' => $lead->getId()])
        );

        if (true === $form) {
            return $this->postActionRedirect(
                [
                    'returnUrl'       => $this->generateUrl('mautic_contact_action', $viewParameters),
                    'viewParameters'  => $viewParameters,
                    'contentTemplate' => 'MauticLeadBundle:Lead:view',
                    'passthroughVars' => [
                        'closeModal' => 1,
                    ],
                ]
            );
        }

        $tmpl = $this->request->get('tmpl', 'index');

        return $this->delegateView(
            [
                'viewParameters' => array_merge(
                    [
                        'tmpl'         => $tmpl,
                        'form'         => $form->createView(),
                        'currentRoute' => $this->generateUrl(
                            'mautic_contact_action',
                            [
                                'objectAction' => 'contactFrequency',
                                'objectId'     => $lead->getId(),
                            ]
                        ),
                        'lead' => $lead,
                    ],
                    $viewParameters
                ),
                'contentTemplate' => 'MauticLeadBundle:Lead:frequency.html.php',
                'passthroughVars' => [
                    'route'  => false,
                    'target' => ($tmpl == 'update') ? '.lead-frequency-options' : null,
                ],
            ]
        );
    }

    /**
     * Deletes the entity.
     *
     * @param   $objectId
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteAction($objectId)
    {
        $page      = $this->get('session')->get('mautic.lead.page', 1);
        $returnUrl = $this->generateUrl('mautic_contact_index', ['page' => $page]);
        $flashes   = [];

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'MauticLeadBundle:Lead:index',
            'passthroughVars' => [
                'activeLink'    => '#mautic_contact_index',
                'mauticContent' => 'lead',
            ],
        ];

        if ($this->request->getMethod() == 'POST') {
            $model  = $this->getModel('lead.lead');
            $entity = $model->getEntity($objectId);

            if ($entity === null) {
                $flashes[] = [
                    'type'    => 'error',
                    'msg'     => 'mautic.lead.lead.error.notfound',
                    'msgVars' => ['%id%' => $objectId],
                ];
            } elseif (!$this->get('mautic.security')->hasEntityAccess(
                'lead:leads:deleteown',
                'lead:leads:deleteother',
                $entity->getPermissionUser()
            )
            ) {
                return $this->accessDenied();
            } elseif ($model->isLocked($entity)) {
                return $this->isLocked($postActionVars, $entity, 'lead.lead');
            } else {
                $model->deleteEntity($entity);

                $identifier = $this->get('translator')->trans($entity->getPrimaryIdentifier());
                $flashes[]  = [
                    'type'    => 'notice',
                    'msg'     => 'mautic.core.notice.deleted',
                    'msgVars' => [
                        '%name%' => $identifier,
                        '%id%'   => $objectId,
                    ],
                ];
            }
        } //else don't do anything

        return $this->postActionRedirect(
            array_merge(
                $postActionVars,
                [
                    'flashes' => $flashes,
                ]
            )
        );
    }

    /**
     * Deletes a group of entities.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function batchDeleteAction()
    {
        $page      = $this->get('session')->get('mautic.lead.page', 1);
        $returnUrl = $this->generateUrl('mautic_contact_index', ['page' => $page]);
        $flashes   = [];

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'MauticLeadBundle:Lead:index',
            'passthroughVars' => [
                'activeLink'    => '#mautic_contact_index',
                'mauticContent' => 'lead',
            ],
        ];

        if ($this->request->getMethod() == 'POST') {
            $model     = $this->getModel('lead');
            $ids       = json_decode($this->request->query->get('ids', '{}'));
            $deleteIds = [];

            // Loop over the IDs to perform access checks pre-delete
            foreach ($ids as $objectId) {
                $entity = $model->getEntity($objectId);

                if ($entity === null) {
                    $flashes[] = [
                        'type'    => 'error',
                        'msg'     => 'mautic.lead.lead.error.notfound',
                        'msgVars' => ['%id%' => $objectId],
                    ];
                } elseif (!$this->get('mautic.security')->hasEntityAccess(
                    'lead:leads:deleteown',
                    'lead:leads:deleteother',
                    $entity->getPermissionUser()
                )
                ) {
                    $flashes[] = $this->accessDenied(true);
                } elseif ($model->isLocked($entity)) {
                    $flashes[] = $this->isLocked($postActionVars, $entity, 'lead', true);
                } else {
                    $deleteIds[] = $objectId;
                }
            }

            // Delete everything we are able to
            if (!empty($deleteIds)) {
                $entities = $model->deleteEntities($deleteIds);

                $flashes[] = [
                    'type'    => 'notice',
                    'msg'     => 'mautic.lead.lead.notice.batch_deleted',
                    'msgVars' => [
                        '%count%' => count($entities),
                    ],
                ];
            }
        } //else don't do anything

        return $this->postActionRedirect(
            array_merge(
                $postActionVars,
                [
                    'flashes' => $flashes,
                ]
            )
        );
    }

    /**
     * Add/remove lead from a list.
     *
     * @param $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function listAction($objectId)
    {
        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model = $this->getModel('lead');
        $lead  = $model->getEntity($objectId);

        if ($lead != null
            && $this->get('mautic.security')->hasEntityAccess(
                'lead:leads:editown',
                'lead:leads:editother',
                $lead->getPermissionUser()
            )
        ) {
            /** @var \Mautic\LeadBundle\Model\ListModel $listModel */
            $listModel = $this->getModel('lead.list');
            $lists     = $listModel->getUserLists();

            // Get a list of lists for the lead
            $leadsLists = $model->getLists($lead, true, true);
        } else {
            $lists = $leadsLists = [];
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'lists'      => $lists,
                    'leadsLists' => $leadsLists,
                    'lead'       => $lead,
                ],
                'contentTemplate' => 'MauticLeadBundle:LeadLists:index.html.php',
            ]
        );
    }
    /**
     * Add/remove lead from a company.
     *
     * @param $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function companyAction($objectId)
    {
        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model = $this->getModel('lead');
        $lead  = $model->getEntity($objectId);

        if ($lead != null
            && $this->get('mautic.security')->hasEntityAccess(
                'lead:leads:editown',
                'lead:leads:editother',
                $lead->getOwner()
            )
        ) {
            /** @var \Mautic\LeadBundle\Model\CompanyModel $companyModel */
            $companyModel = $this->getModel('lead.company');
            $companies    = $companyModel->getUserCompanies();

            // Get a list of lists for the lead
            $companyLeads = $lead->getCompanies();
            foreach ($companyLeads as $cl) {
                $companyLead[$cl->getId()] = $cl->getId();
            }
        } else {
            $companies = $companyLead = [];
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'companies'   => $companies,
                    'companyLead' => $companyLead,
                    'lead'        => $lead,
                ],
                'contentTemplate' => 'MauticLeadBundle:Lead:company.html.php',
            ]
        );
    }

    /**
     * Add/remove lead from a campaign.
     *
     * @param $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function campaignAction($objectId)
    {
        $model = $this->getModel('lead');
        $lead  = $model->getEntity($objectId);

        if ($lead != null
            && $this->get('mautic.security')->hasEntityAccess(
                'lead:leads:editown',
                'lead:leads:editother',
                $lead->getPermissionUser()
            )
        ) {
            /** @var \Mautic\CampaignBundle\Model\CampaignModel $campaignModel */
            $campaignModel  = $this->getModel('campaign');
            $campaigns      = $campaignModel->getPublishedCampaigns(true);
            $leadsCampaigns = $campaignModel->getLeadCampaigns($lead, true);

            foreach ($campaigns as $c) {
                $campaigns[$c['id']]['inCampaign'] = (isset($leadsCampaigns[$c['id']])) ? true : false;
            }
        } else {
            $campaigns = [];
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'campaigns' => $campaigns,
                    'lead'      => $lead,
                ],
                'contentTemplate' => 'MauticLeadBundle:LeadCampaigns:index.html.php',
            ]
        );
    }

    /**
     * @param int  $objectId
     * @param bool $ignorePost
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function importAction($objectId = 0, $ignorePost = false)
    {
        //Auto detect line endings for the file to work around MS DOS vs Unix new line characters
        ini_set('auto_detect_line_endings', true);

        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model   = $this->getModel('lead');
        $session = $this->get('session');

        if (!$this->get('mautic.security')->isGranted('lead:leads:create')) {
            return $this->accessDenied();
        }

        // Move the file to cache and rename it
        $forceStop = $this->request->get('cancel', false);
        $step      = ($forceStop) ? 1 : $session->get('mautic.lead.import.step', 1);
        $tmpDir    = $this->get('mautic.helper.paths')->getSystemPath('tmp');
        $username  = $this->get('mautic.helper.user')->getUser()->getUsername();
        $fileName  = $username.'_leadimport.csv';
        $fullPath  = $tmpDir.'/'.$fileName;
        $complete  = false;
        if (!file_exists($fullPath)) {
            // Force step one if the file doesn't exist
            $step = 1;
            $session->set('mautic.lead.import.step', 1);
        }

        $progress = $session->get('mautic.lead.import.progress', [0, 0]);
        $stats    = $session->get('mautic.lead.import.stats', ['merged' => 0, 'created' => 0, 'ignored' => 0, 'failures' => []]);
        $action   = $this->generateUrl('mautic_contact_action', ['objectAction' => 'import']);

        switch ($step) {
            case 1:
                // Upload file

                if ($forceStop) {
                    $this->resetImport($fullPath);
                }

                $session->set('mautic.lead.import.headers', []);
                $form = $this->get('form.factory')->create('lead_import', [], ['action' => $action]);
                break;
            case 2:
                // Match fields

                /** @var \Mautic\LeadBundle\Model\FieldModel $pluginModel */
                $fieldModel = $this->getModel('lead.field');

                $leadFields   = $fieldModel->getFieldList(false, false);
                $importFields = $session->get('mautic.lead.import.importfields', []);

                $form = $this->get('form.factory')->create(
                    'lead_field_import',
                    [],
                    [
                        'action'        => $action,
                        'lead_fields'   => $leadFields,
                        'import_fields' => $importFields,
                    ]
                );

                break;
            case 3:
                // Just show the progress form
                $session->set('mautic.lead.import.step', 4);
                break;

            case 4:
                ignore_user_abort(true);

                $inProgress = $session->get('mautic.lead.import.inprogress', false);
                $checks     = $session->get('mautic.lead.import.progresschecks', 1);
                if (true || !$inProgress || $checks > 5) {
                    $session->set('mautic.lead.import.inprogress', true);
                    $session->set('mautic.lead.import.progresschecks', 1);

                    // Batch process
                    $defaultOwner = $session->get('mautic.lead.import.defaultowner', null);
                    $defaultList  = $session->get('mautic.lead.import.defaultlist', null);
                    $defaultTags  = $session->get('mautic.lead.import.defaulttags', null);
                    $headers      = $session->get('mautic.lead.import.headers', []);
                    $importFields = $session->get('mautic.lead.import.fields', []);

                    $file = new \SplFileObject($fullPath);
                    if ($file !== false) {
                        $lineNumber = $progress[0];

                        if ($lineNumber > 0) {
                            $file->seek($lineNumber);
                        }

                        $config    = $session->get('mautic.lead.import.config');
                        $batchSize = $config['batchlimit'];

                        while ($batchSize && !$file->eof()) {
                            $data = $file->fgetcsv($config['delimiter'], $config['enclosure'], $config['escape']);
                            array_walk($data, create_function('&$val', '$val = trim($val);'));

                            if ($lineNumber === 0) {
                                ++$lineNumber;
                                continue;
                            }

                            ++$lineNumber;

                            // Increase progress count
                            ++$progress[0];

                            // Decrease batch count
                            --$batchSize;

                            if (is_array($data) && $dataCount = count($data)) {
                                // Ensure the number of headers are equal with data
                                $headerCount = count($headers);

                                if ($headerCount !== $dataCount) {
                                    $diffCount = ($headerCount - $dataCount);

                                    if ($diffCount < 0) {
                                        ++$stats['ignored'];
                                        $stats['failures'][$lineNumber] = $this->get('translator')->trans(
                                            'mautic.lead.import.error.header_mismatch'
                                        );

                                        continue;
                                    }
                                    // Fill in the data with empty string
                                    $fill = array_fill($dataCount, $diffCount, '');
                                    $data = $data + $fill;
                                }

                                $data = array_combine($headers, $data);
                                try {
                                    $prevent = false;
                                    foreach ($data as $key => $value) {
                                        if ($value != '') {
                                            $prevent = true;
                                            break;
                                        }
                                    }
                                    if ($prevent) {
                                        $merged = $model->importLead($importFields, $data, $defaultOwner, $defaultList, $defaultTags);
                                        if ($merged) {
                                            ++$stats['merged'];
                                        } else {
                                            ++$stats['created'];
                                        }
                                    } else {
                                        ++$stats['ignored'];
                                        $stats['failures'][$lineNumber] = $this->get('translator')->trans(
                                            'mautic.lead.import.error.line_empty'
                                        );
                                    }
                                } catch (\Exception $e) {
                                    // Email validation likely failed
                                    ++$stats['ignored'];
                                    $stats['failures'][$lineNumber] = $e->getMessage();
                                }
                            } else {
                                ++$stats['ignored'];
                                $stats['failures'][$lineNumber] = $this->get('translator')->trans('mautic.lead.import.error.line_empty');
                            }
                        }

                        $session->set('mautic.lead.import.stats', $stats);
                    }

                    // Close the file
                    $file = null;

                    // Clear in progress
                    if ($progress[0] >= $progress[1]) {
                        $progress[0] = $progress[1];
                        $this->resetImport($fullPath);
                        $complete = true;
                    } else {
                        $complete = false;
                        $session->set('mautic.lead.import.inprogress', false);
                        $session->set('mautic.lead.import.progress', $progress);
                    }

                    break;
                } else {
                    ++$checks;
                    $session->set('mautic.lead.import.progresschecks', $checks);
                }
        }

        ///Check for a submitted form and process it
        if (!$ignorePost && $this->request->getMethod() == 'POST') {
            if (isset($form) && !$cancelled = $this->isFormCancelled($form)) {
                $valid = $this->isFormValid($form);
                switch ($step) {
                    case 1:
                        if ($valid) {
                            if (file_exists($fullPath)) {
                                unlink($fullPath);
                            }

                            $fileData = $form['file']->getData();
                            if (!empty($fileData)) {
                                $errorMessage    = null;
                                $errorParameters = [];
                                try {
                                    $fileData->move($tmpDir, $fileName);

                                    $file = new \SplFileObject($fullPath);

                                    $config = $form->getData();
                                    unset($config['file']);
                                    unset($config['start']);

                                    foreach ($config as $key => &$c) {
                                        $c = htmlspecialchars_decode($c);

                                        if ($key == 'batchlimit') {
                                            $c = (int) $c;
                                        }
                                    }

                                    $session->set('mautic.lead.import.config', $config);

                                    if ($file !== false) {
                                        // Get the headers for matching
                                        $headers = $file->fgetcsv($config['delimiter'], $config['enclosure'], $config['escape']);

                                        // Get the number of lines so we can track progress
                                        $file->seek(PHP_INT_MAX);
                                        $linecount = $file->key();

                                        if (!empty($headers) && is_array($headers)) {
                                            array_walk($headers, create_function('&$val', '$val = trim($val);'));
                                            $session->set('mautic.lead.import.headers', $headers);
                                            sort($headers);
                                            $headers = array_combine($headers, $headers);
                                            $session->set('mautic.lead.import.step', 2);
                                            $session->set('mautic.lead.import.importfields', $headers);
                                            $session->set('mautic.lead.import.progress', [0, $linecount]);

                                            return $this->importAction(0, true);
                                        }
                                    }
                                } catch (FileException $e) {
                                    if (strpos($e->getMessage(), 'upload_max_filesize') !== false) {
                                        $errorMessage    = 'mautic.lead.import.filetoolarge';
                                        $errorParameters = [
                                            '%upload_max_filesize%' => ini_get('upload_max_filesize'),
                                        ];
                                    } else {
                                        $errorMessage = 'mautic.lead.import.filenotreadable';
                                    }
                                } catch (\Exception $e) {
                                    $errorMessage = 'mautic.lead.import.filenotreadable';
                                } finally {
                                    if (!is_null($errorMessage)) {
                                        $form->addError(
                                            new FormError(
                                                $this->get('translator')->trans($errorMessage, $errorParameters, 'validators')
                                            )
                                        );
                                    }
                                }
                            }
                        }
                        break;
                    case 2:
                        // Save matched fields
                        $matchedFields = $form->getData();

                        if (empty($matchedFields)) {
                            $this->resetImport($fullPath);

                            return $this->importAction(0, true);
                        }

                        $owner = $matchedFields['owner'];
                        unset($matchedFields['owner']);

                        $list = $matchedFields['list'];
                        unset($matchedFields['list']);

                        $tagCollection = $matchedFields['tags'];
                        $tags          = [];
                        foreach ($tagCollection as $tag) {
                            $tags[] = $tag->getTag();
                        }
                        unset($matchedFields['tags']);

                        foreach ($matchedFields as $k => $f) {
                            if (empty($f)) {
                                unset($matchedFields[$k]);
                            } else {
                                $matchedFields[$k] = trim($matchedFields[$k]);
                            }
                        }

                        if (empty($matchedFields)) {
                            $form->addError(
                                new FormError(
                                    $this->get('translator')->trans('mautic.lead.import.matchfields', [], 'validators')
                                )
                            );
                        } else {
                            $defaultOwner = ($owner) ? $owner->getId() : null;
                            $session->set('mautic.lead.import.fields', $matchedFields);
                            $session->set('mautic.lead.import.defaultowner', $defaultOwner);
                            $session->set('mautic.lead.import.defaultlist', $list);
                            $session->set('mautic.lead.import.defaulttags', $tags);
                            $session->set('mautic.lead.import.step', 3);

                            return $this->importAction(0, true);
                        }
                        break;

                    default:
                        // Done or something wrong

                        $this->resetImport($fullPath);

                        break;
                }
            } else {
                $this->resetImport($fullPath);

                return $this->importAction(0, true);
            }
        }

        if ($step === 1 || $step === 2) {
            $contentTemplate = 'MauticLeadBundle:Import:form.html.php';
            $viewParameters  = ['form' => $form->createView()];
        } else {
            $contentTemplate = 'MauticLeadBundle:Import:progress.html.php';
            $viewParameters  = [
                'progress' => $progress,
                'stats'    => $stats,
                'complete' => $complete,
            ];
        }

        if (!$complete && $this->request->query->has('importbatch')) {
            // Ajax request to batch process so just return ajax response unless complete

            return new JsonResponse(['success' => 1, 'ignore_wdt' => 1]);
        } else {
            return $this->delegateView(
                [
                    'viewParameters'  => $viewParameters,
                    'contentTemplate' => $contentTemplate,
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_contact_index',
                        'mauticContent' => 'leadImport',
                        'route'         => $this->generateUrl(
                            'mautic_contact_action',
                            [
                                'objectAction' => 'import',
                            ]
                        ),
                        'step'     => $step,
                        'progress' => $progress,
                    ],
                ]
            );
        }
    }

    /**
     * @param $filepath
     */
    private function resetImport($filepath)
    {
        $session = $this->get('session');
        $session->set('mautic.lead.import.stats', ['merged' => 0, 'created' => 0, 'ignored' => 0]);
        $session->set('mautic.lead.import.headers', []);
        $session->set('mautic.lead.import.step', 1);
        $session->set('mautic.lead.import.progress', [0, 0]);
        $session->set('mautic.lead.import.fields', []);
        $session->set('mautic.lead.import.defaultowner', null);
        $session->set('mautic.lead.import.defaultlist', null);
        $session->set('mautic.lead.import.inprogress', false);
        $session->set('mautic.lead.import.importfields', []);

        unlink($filepath);
    }

    /**
     * @param int $objectId
     *
     * @return JsonResponse
     */
    public function emailAction($objectId = 0)
    {
        $valid = $cancelled = false;

        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model = $this->getModel('lead');

        /** @var \Mautic\LeadBundle\Entity\Lead $lead */
        $lead = $model->getEntity($objectId);

        if ($lead === null
            || !$this->get('mautic.security')->hasEntityAccess(
                'lead:leads:viewown',
                'lead:leads:viewother',
                $lead->getPermissionUser()
            )
        ) {
            return $this->modalAccessDenied();
        }

        $leadFields       = $lead->getProfileFields();
        $leadFields['id'] = $lead->getId();
        $leadEmail        = $leadFields['email'];
        $leadName         = $leadFields['firstname'].' '.$leadFields['lastname'];

        // Set onwer ID to be the current user ID so it will use his signature
        $leadFields['owner_id'] = $this->get('mautic.helper.user')->getUser()->getId();

        // Check if lead has a bounce status
        /** @var \Mautic\EmailBundle\Model\EmailModel $emailModel */
        $emailModel = $this->getModel('email');
        $dnc        = $emailModel->getRepository()->checkDoNotEmail($leadEmail);

        $inList = ($this->request->getMethod() == 'GET')
            ? $this->request->get('list', 0)
            : $this->request->request->get(
                'lead_quickemail[list]',
                0,
                true
            );
        $email  = ['list' => $inList];
        $action = $this->generateUrl('mautic_contact_action', ['objectAction' => 'email', 'objectId' => $objectId]);
        $form   = $this->get('form.factory')->create('lead_quickemail', $email, ['action' => $action]);

        if ($this->request->getMethod() == 'POST') {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $email = $form->getData();

                    $bodyCheck = trim(strip_tags($email['body']));
                    if (!empty($bodyCheck)) {
                        $mailer = $this->get('mautic.helper.mailer')->getMailer();

                        // To lead
                        $mailer->addTo($leadEmail, $leadName);

                        // From user
                        $user = $this->get('mautic.helper.user')->getUser();

                        $mailer->setFrom(
                            $email['from'],
                            empty($email['fromname']) ? '' : $email['fromname']
                        );

                        // Set Content
                        $mailer->setBody($email['body']);
                        $mailer->parsePlainText($email['body']);

                        // Set lead
                        $mailer->setLead($leadFields);
                        $mailer->setIdHash();

                        $mailer->setSubject($email['subject']);

                        // Ensure safe emoji for notification
                        $subject = EmojiHelper::toHtml($email['subject']);
                        if ($mailer->send(true, false, false)) {
                            $mailer->createEmailStat();
                            $this->addFlash(
                                'mautic.lead.email.notice.sent',
                                [
                                    '%subject%' => $subject,
                                    '%email%'   => $leadEmail,
                                ]
                            );
                        } else {
                            $errors = $mailer->getErrors();

                            // Unset the array of failed email addresses
                            if (isset($errors['failures'])) {
                                unset($errors['failures']);
                            }

                            $form->addError(
                                new FormError(
                                    $this->get('translator')->trans(
                                        'mautic.lead.email.error.failed',
                                        [
                                            '%subject%' => $subject,
                                            '%email%'   => $leadEmail,
                                            '%error%'   => (is_array($errors)) ? implode('<br />', $errors) : $errors,
                                        ],
                                        'flashes'
                                    )
                                )
                            );
                            $valid = false;
                        }
                    } else {
                        $form['body']->addError(
                            new FormError(
                                $this->get('translator')->trans('mautic.lead.email.body.required', [], 'validators')
                            )
                        );
                        $valid = false;
                    }
                }
            }
        }

        if (empty($leadEmail) || $valid || $cancelled) {
            if ($inList) {
                $route          = 'mautic_contact_index';
                $viewParameters = [
                    'page' => $this->get('session')->get('mautic.lead.page', 1),
                ];
                $func = 'index';
            } else {
                $route          = 'mautic_contact_action';
                $viewParameters = [
                    'objectAction' => 'view',
                    'objectId'     => $objectId,
                ];
                $func = 'view';
            }

            return $this->postActionRedirect(
                [
                    'returnUrl'       => $this->generateUrl($route, $viewParameters),
                    'viewParameters'  => $viewParameters,
                    'contentTemplate' => 'MauticLeadBundle:Lead:'.$func,
                    'passthroughVars' => [
                        'mauticContent' => 'lead',
                        'closeModal'    => 1,
                    ],
                ]
            );
        }

        return $this->ajaxAction(
            [
                'contentTemplate' => 'MauticLeadBundle:Lead:email.html.php',
                'viewParameters'  => [
                    'form' => $form->createView(),
                    'dnc'  => $dnc,
                ],
                'passthroughVars' => [
                    'mauticContent' => 'leadEmail',
                    'route'         => false,
                ],
            ]
        );
    }

    /**
     * Bulk edit lead lists.
     *
     * @param int $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function batchListsAction($objectId = 0)
    {
        if ($this->request->getMethod() == 'POST') {
            /** @var \Mautic\LeadBundle\Model\LeadModel $model */
            $model = $this->getModel('lead');
            $data  = $this->request->request->get('lead_batch', [], true);
            $ids   = json_decode($data['ids'], true);

            $entities = [];
            if (is_array($ids)) {
                $entities = $model->getEntities(
                    [
                        'filter' => [
                            'force' => [
                                [
                                    'column' => 'l.id',
                                    'expr'   => 'in',
                                    'value'  => $ids,
                                ],
                            ],
                        ],
                        'ignore_paginator' => true,
                    ]
                );
            }

            $count = 0;
            foreach ($entities as $lead) {
                if ($this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $lead->getPermissionUser())) {
                    ++$count;

                    if (!empty($data['add'])) {
                        $model->addToLists($lead, $data['add']);
                    }

                    if (!empty($data['remove'])) {
                        $model->removeFromLists($lead, $data['remove']);
                    }
                }
            }

            $this->addFlash(
                'mautic.lead.batch_leads_affected',
                [
                    'pluralCount' => $count,
                    '%count%'     => $count,
                ]
            );

            return new JsonResponse(
                [
                    'closeModal' => true,
                    'flashes'    => $this->getFlashContent(),
                ]
            );
        } else {
            // Get a list of lists
            /** @var \Mautic\LeadBundle\Model\ListModel $model */
            $model = $this->getModel('lead.list');
            $lists = $model->getUserLists();
            $items = [];
            foreach ($lists as $list) {
                $items[$list['id']] = $list['name'];
            }

            $route = $this->generateUrl(
                'mautic_contact_action',
                [
                    'objectAction' => 'batchLists',
                ]
            );

            return $this->delegateView(
                [
                    'viewParameters' => [
                        'form' => $this->createForm(
                            'lead_batch',
                            [],
                            [
                                'items'  => $items,
                                'action' => $route,
                            ]
                        )->createView(),
                    ],
                    'contentTemplate' => 'MauticLeadBundle:Batch:form.html.php',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_contact_index',
                        'mauticContent' => 'leadBatch',
                        'route'         => $route,
                    ],
                ]
            );
        }
    }

    /**
     * Bulk edit lead campaigns.
     *
     * @param int $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function batchCampaignsAction($objectId = 0)
    {
        /** @var \Mautic\CampaignBundle\Model\CampaignModel $campaignModel */
        $campaignModel = $this->getModel('campaign');

        if ($this->request->getMethod() == 'POST') {
            /** @var \Mautic\LeadBundle\Model\LeadModel $model */
            $model = $this->getModel('lead');
            $data  = $this->request->request->get('lead_batch', [], true);
            $ids   = json_decode($data['ids'], true);

            $entities = [];
            if (is_array($ids)) {
                $entities = $model->getEntities(
                    [
                        'filter' => [
                            'force' => [
                                [
                                    'column' => 'l.id',
                                    'expr'   => 'in',
                                    'value'  => $ids,
                                ],
                            ],
                        ],
                        'ignore_paginator' => true,
                    ]
                );
            }

            foreach ($entities as $key => $lead) {
                if (!$this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $lead->getPermissionUser())) {
                    unset($entities[$key]);
                }
            }

            $add    = (!empty($data['add'])) ? $data['add'] : [];
            $remove = (!empty($data['remove'])) ? $data['remove'] : [];

            if ($count = count($entities)) {
                $campaigns = $campaignModel->getEntities(
                    [
                        'filter' => [
                            'force' => [
                                [
                                    'column' => 'c.id',
                                    'expr'   => 'in',
                                    'value'  => array_merge($add, $remove),
                                ],
                            ],
                        ],
                        'ignore_paginator' => true,
                    ]
                );

                if (!empty($add)) {
                    foreach ($add as $cid) {
                        $campaignModel->addLeads($campaigns[$cid], $entities, true);
                    }
                }

                if (!empty($remove)) {
                    foreach ($remove as $cid) {
                        $campaignModel->removeLeads($campaigns[$cid], $entities, true);
                    }
                }
            }

            $this->addFlash(
                'mautic.lead.batch_leads_affected',
                [
                    'pluralCount' => $count,
                    '%count%'     => $count,
                ]
            );

            return new JsonResponse(
                [
                    'closeModal' => true,
                    'flashes'    => $this->getFlashContent(),
                ]
            );
        } else {
            // Get a list of campaigns
            $campaigns = $campaignModel->getPublishedCampaigns(true);
            $items     = [];
            foreach ($campaigns as $campaign) {
                $items[$campaign['id']] = $campaign['name'];
            }

            $route = $this->generateUrl(
                'mautic_contact_action',
                [
                    'objectAction' => 'batchCampaigns',
                ]
            );

            return $this->delegateView(
                [
                    'viewParameters' => [
                        'form' => $this->createForm(
                            'lead_batch',
                            [],
                            [
                                'items'  => $items,
                                'action' => $route,
                            ]
                        )->createView(),
                    ],
                    'contentTemplate' => 'MauticLeadBundle:Batch:form.html.php',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_contact_index',
                        'mauticContent' => 'leadBatch',
                        'route'         => $route,
                    ],
                ]
            );
        }
    }

    /**
     * Bulk add leads to the DNC list.
     *
     * @param int $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function batchDncAction($objectId = 0)
    {
        if ($this->request->getMethod() == 'POST') {
            /** @var \Mautic\LeadBundle\Model\LeadModel $model */
            $model = $this->getModel('lead');
            $data  = $this->request->request->get('lead_batch_dnc', [], true);
            $ids   = json_decode($data['ids'], true);

            $entities = [];
            if (is_array($ids)) {
                $entities = $model->getEntities(
                    [
                        'filter' => [
                            'force' => [
                                [
                                    'column' => 'l.id',
                                    'expr'   => 'in',
                                    'value'  => $ids,
                                ],
                            ],
                        ],
                        'ignore_paginator' => true,
                    ]
                );
            }

            if ($count = count($entities)) {
                $persistEntities = [];
                foreach ($entities as $lead) {
                    if ($this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $lead->getPermissionUser())) {
                        if ($model->addDncForLead($lead, 'email', $data['reason'], DoNotContact::MANUAL)) {
                            $persistEntities[] = $lead;
                        }
                    }
                }

                // Save entities
                $model->saveEntities($persistEntities);
            }

            $this->addFlash(
                'mautic.lead.batch_leads_affected',
                [
                    'pluralCount' => $count,
                    '%count%'     => $count,
                ]
            );

            return new JsonResponse(
                [
                    'closeModal' => true,
                    'flashes'    => $this->getFlashContent(),
                ]
            );
        } else {
            $route = $this->generateUrl(
                'mautic_contact_action',
                [
                    'objectAction' => 'batchDnc',
                ]
            );

            return $this->delegateView(
                [
                    'viewParameters' => [
                        'form' => $this->createForm(
                            'lead_batch_dnc',
                            [],
                            [
                                'action' => $route,
                            ]
                        )->createView(),
                    ],
                    'contentTemplate' => 'MauticLeadBundle:Batch:form.html.php',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_contact_index',
                        'mauticContent' => 'leadBatch',
                        'route'         => $route,
                    ],
                ]
            );
        }
    }

    /**
     * Bulk edit lead stages.
     *
     * @param int $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function batchStagesAction($objectId = 0)
    {
        if ($this->request->getMethod() == 'POST') {
            /** @var \Mautic\LeadBundle\Model\LeadModel $model */
            $model = $this->getModel('lead');
            $data  = $this->request->request->get('lead_batch_stage', [], true);
            $ids   = json_decode($data['ids'], true);

            $entities = [];
            if (is_array($ids)) {
                $entities = $model->getEntities(
                    [
                        'filter' => [
                            'force' => [
                                [
                                    'column' => 'l.id',
                                    'expr'   => 'in',
                                    'value'  => $ids,
                                ],
                            ],
                        ],
                        'ignore_paginator' => true,
                    ]
                );
            }

            $count = 0;
            foreach ($entities as $lead) {
                if ($this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $lead->getPermissionUser())) {
                    ++$count;

                    if (!empty($data['addstage'])) {
                        $stageModel = $this->getModel('stage');

                        $stage = $stageModel->getEntity((int) $data['addstage']);
                        $model->addToStages($lead, $stage);
                    }

                    if (!empty($data['removestage'])) {
                        $stage = $stageModel->getEntity($data['removestage']);
                        $model->removeFromStages($lead, $stage);
                    }
                }
            }
            // Save entities
            $model->saveEntities($entities);
            $this->addFlash(
                'mautic.lead.batch_leads_affected',
                [
                    'pluralCount' => $count,
                    '%count%'     => $count,
                ]
            );

            return new JsonResponse(
                [
                    'closeModal' => true,
                    'flashes'    => $this->getFlashContent(),
                ]
            );
        } else {
            // Get a list of lists
            /** @var \Mautic\StageBundle\Model\StageModel $model */
            $model  = $this->getModel('stage');
            $stages = $model->getUserStages();
            $items  = [];
            foreach ($stages as $stage) {
                $items[$stage['id']] = $stage['name'];
            }

            $route = $this->generateUrl(
                'mautic_contact_action',
                [
                    'objectAction' => 'batchStages',
                ]
            );

            return $this->delegateView(
                [
                    'viewParameters' => [
                        'form' => $this->createForm(
                            'lead_batch_stage',
                            [],
                            [
                                'items'  => $items,
                                'action' => $route,
                            ]
                        )->createView(),
                    ],
                    'contentTemplate' => 'MauticLeadBundle:Batch:form.html.php',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_contact_index',
                        'mauticContent' => 'leadBatch',
                        'route'         => $route,
                    ],
                ]
            );
        }
    }

    /**
     * Bulk export contacts.
     *
     * @return array|JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function batchExportAction()
    {
        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'lead:leads:viewown',
                'lead:leads:viewother',
                'lead:leads:create',
                'lead:leads:editown',
                'lead:leads:editother',
                'lead:leads:deleteown',
                'lead:leads:deleteother',
            ],
            'RETURN_ARRAY'
        );

        if (!$permissions['lead:leads:viewown'] && !$permissions['lead:leads:viewother']) {
            return $this->accessDenied();
        }

        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model      = $this->getModel('lead');
        $session    = $this->get('session');
        $search     = $session->get('mautic.lead.filter', '');
        $orderBy    = $session->get('mautic.lead.orderby', 'l.last_active');
        $orderByDir = $session->get('mautic.lead.orderbydir', 'DESC');
        $ids        = $this->request->get('ids');

        $filter     = ['string' => $search, 'force' => ''];
        $translator = $this->get('translator');
        $anonymous  = $translator->trans('mautic.lead.lead.searchcommand.isanonymous');
        $mine       = $translator->trans('mautic.core.searchcommand.ismine');
        $indexMode  = $session->get('mautic.lead.indexmode', 'list');
        $dataType   = $this->request->get('filetype', 'csv');

        if (!empty($ids)) {
            $filter['force'] = [
                [
                    'column' => 'l.id',
                    'expr'   => 'in',
                    'value'  => json_decode($ids, true),
                ],
            ];
        } else {
            if ($indexMode != 'list' || ($indexMode == 'list' && strpos($search, $anonymous) === false)) {
                //remove anonymous leads unless requested to prevent clutter
                $filter['force'] .= " !$anonymous";
            }

            if (!$permissions['lead:leads:viewother']) {
                $filter['force'] .= " $mine";
            }
        }

        $args = [
            'start'          => 0,
            'limit'          => 200,
            'filter'         => $filter,
            'orderBy'        => $orderBy,
            'orderByDir'     => $orderByDir,
            'withTotalCount' => true,
        ];

        $resultsCallback = function ($contact) {
            return $contact->getProfileFields();
        };

        $toExport = $this->getDataForExport($model, $args, $resultsCallback);

        return $this->exportResultsAs($toExport, $dataType, 'contacts');
    }
    /**
     * @Route("/lucky/number")
     */
    public function numberAction()
        {
            $number = mt_rand(0, 100);

            return new Response(
                '<html><body>Lucky number: '.$number.'</body></html>'
            );
        }
}
