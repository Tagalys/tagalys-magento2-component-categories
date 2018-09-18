<?php
    namespace Tagalys\Categories\Controller\Category;

    class View extends \Magento\Catalog\Controller\Category\View
    {
        public function execute()
        {
            // if category is not selected in Tagalys configuration, pass
            $tagalysConfiguration = $this->_objectManager->get('\Tagalys\Sync\Helper\Configuration');
            $categoryIdsForTagalys = $tagalysConfiguration->getConfig('category_ids', true);
            $categoryId = (int)$this->getRequest()->getParam('id', false);
            if ($tagalysConfiguration->getConfig('module:listingpages:enabled') == '0' || !in_array($categoryId, $categoryIdsForTagalys)) {
                return parent::execute();
            }
            // if display mode is cms block only, pass
            try {
                $category = $this->categoryRepository->get($categoryId, $this->_storeManager->getStore()->getId());
                $displayMode = $category->getDisplayMode();
                if ($displayMode == \Magento\Catalog\Model\Category::DM_PAGE) {
                    return parent::execute();
                }
            } catch (Exception $e) {
                // on exception (likely category not found), pass
                return parent::execute();
            }
            // category is selected for Tagalys and display mode has products
            try {
                $magentoVersion = $this->_objectManager->get('Magento\Framework\App\ProductMetadataInterface')->getVersion();
                $magentoVersionComponents = explode('.', $magentoVersion);
                $minorVersion = intval($magentoVersionComponents[1]);
                $pointVersion = intval($magentoVersionComponents[2]);
                if ($minorVersion === 1 || ($minorVersion === 2 && $pointVersion <= 3)) {
                    if ($this->_request->getParam(\Magento\Framework\App\ActionInterface::PARAM_NAME_URL_ENCODED)) {
                        return $this->resultRedirectFactory->create()->setUrl($this->_redirect->getRedirectUrl());
                    }
                } else {
                    if (!$this->_request->getParam('___from_store')
                        && $this->_request->getParam(self::PARAM_NAME_URL_ENCODED)
                    ) {
                        return $this->resultRedirectFactory->create()->setUrl($this->_redirect->getRedirectUrl());
                    }
                }
                
                $category = $this->_initCategory();
                if ($category) {
                    $settings = $this->_catalogDesign->getDesignSettings($category);

                    // apply custom design
                    if ($settings->getCustomDesign()) {
                        $this->_catalogDesign->applyCustomDesign($settings->getCustomDesign());
                    }

                    $this->_catalogSession->setLastViewedCategoryId($category->getId());

                    $page = $this->resultPageFactory->create();
                    // apply custom layout (page) template once the blocks are generated
                    if ($settings->getPageLayout()) {
                        $page->getConfig()->setPageLayout($settings->getPageLayout());
                    }

                    $hasChildren = $category->hasChildren();
                    if ($tagalysConfiguration->getConfig('listing_pages:override_layout') == '1') {
                        $page->getConfig()->setPageLayout($tagalysConfiguration->getConfig('listing_pages:override_layout_name'));
                        $type = 'default_without_children';
                    } else {
                        if ($category->getIsAnchor()) {
                            $type = $hasChildren ? 'layered' : 'layered_without_children';
                        } else {
                            $type = $hasChildren ? 'default' : 'default_without_children';
                        }
                    }

                    if (!$hasChildren) {
                        // Two levels removed from parent.  Need to add default page type.
                        $parentType = strtok($type, '_');
                        if ($minorVersion === 1) {
                            $page->addPageLayoutHandles(['type' => $parentType], null, false);
                        } else {
                            $page->addPageLayoutHandles(['type' => $parentType]);
                        }
                    }
                    if ($minorVersion === 1) {
                        $page->addPageLayoutHandles(['type' => $type, 'id' => $category->getId()]);
                    } else {
                        $page->addPageLayoutHandles(['type' => $type], null, false);
                        $page->addPageLayoutHandles(['id' => $category->getId()]);
                    }

                    // apply custom layout update once layout is loaded
                    $layoutUpdates = $settings->getLayoutUpdates();
                    if ($layoutUpdates && is_array($layoutUpdates)) {
                        foreach ($layoutUpdates as $layoutUpdate) {
                            $page->addUpdate($layoutUpdate);
                            if ($minorVersion === 1) {
                                $page->addPageLayoutHandles(['layout_update' => md5($layoutUpdate)]);
                            } else {
                                $page->addPageLayoutHandles(['layout_update' => md5($layoutUpdate)], null, false);
                            }
                        }
                    }
                    $block = $page->getLayout()->getBlock('category.products')->setTemplate('Tagalys_Categories::category.phtml');

                    $page->getConfig()->addBodyClass('page-products')
                        ->addBodyClass('categorypath-' . $this->categoryUrlPathGenerator->getUrlPath($category))
                        ->addBodyClass('category-' . $category->getUrlKey());

                    return $page;
                } elseif (!$this->getResponse()->isRedirect()) {
                    return $this->resultForwardFactory->create()->forward('noroute');
                }
            } catch (NoSuchEntityException $e) {
                // on exception, pass
                return parent::execute();
            }
        }
    }
