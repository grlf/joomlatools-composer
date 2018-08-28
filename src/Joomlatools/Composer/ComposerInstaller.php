<?php
/**
 * Joomlatools Composer plugin - https://github.com/joomlatools/joomlatools-composer
 *
 * @copyright	Copyright (C) 2011 - 2016 Johan Janssens and Timble CVBA. (http://www.timble.net)
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html>
 * @link		http://github.com/joomlatools/joomlatools-composer for the canonical source repository
 */

namespace Joomlatools\Composer;

use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Installer\LibraryInstaller;

use Joomlatools\Joomla\Bootstrapper;
use Joomlatools\Joomla\Util;

use Symfony\Component\Finder\Finder;

/**
 * Composer installer class
 *
 * @author  Steven Rombauts <https://github.com/stevenrombauts>
 * @package Joomlatools\Composer
 */
class ComposerInstaller extends LibraryInstaller
{
    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);

        $this->addModificationWarningToFiles($package);
        
        $platformStr = Util::isJoomlatoolsPlatform() ? 'Joomlatools Platform' : 'Joomla';

        //Check if this package was already installed into Joomla before composer
	    if (
		    $this->isInstalled($repo, $package) ||
		    Util::isForcingUpdate($package)
	    ) {
	        if ($this->io->isVerbose()) {
		        $this->io->write(sprintf("  - Queuing <comment>%s</comment> for upgrading in %s", $package->getName(), $platformStr), true);
	        }
	        
	        TaskQueue::getInstance()->enqueue(array('update', $package, $this->getInstallPath($package)));

        } else {
	        if ($this->io->isVerbose()) {
		        $this->io->write(sprintf("  - Queuing <comment>%s</comment> for installation in %s", $package->getName(), $platformStr), true);
	        }

	        TaskQueue::getInstance()->enqueue(array('install', $package, $this->getInstallPath($package)));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        parent::update($repo, $initial, $target);

        $this->addModificationWarningToFiles($target);

        $platformStr = Util::isJoomlatoolsPlatform() ? 'Joomlatools Platform' : 'Joomla';

        if ($this->io->isVerbose()) {
            $this->io->write(sprintf("  - Queuing <comment>%s</comment> for upgrading in %s", $target->getName(), $platformStr), true);
        }

        TaskQueue::getInstance()->enqueue(array('update', $target, $this->getInstallPath($target)));
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (!$repo->hasPackage($package)) {
            throw new \InvalidArgumentException('Package is not installed: '.$package);
        }

        $platformStr = Util::isJoomlatoolsPlatform() ? 'Joomlatools Platform' : 'Joomla';

        if ($this->io->isVerbose()) {
            $this->io->write(sprintf("  - Queuing <comment>%s</comment> for removal from %s", $package->getName(), $platformStr), true);
        }

        TaskQueue::getInstance()->enqueue(array('uninstall', $package, $this->getInstallPath($package)));

        // Find the manifest and set it aside so we can query it when actually uninstalling the extension
        $installPath = $this->getInstallPath($package);
        $manifest    = Util::getPackageManifest($installPath);
        $prefix      = str_replace(DIRECTORY_SEPARATOR, '-', $package->getName());
        $tmpFile     = tempnam(sys_get_temp_dir(), $prefix);

        if (copy($manifest, $tmpFile))
        {
            Util::setPackageManifest($installPath, $tmpFile);

            parent::uninstall($repo, $package);
        }
        else
        {
            if ($this->io->isVerbose()) {
                $this->io->write(sprintf("    [<error>ERROR</error>] Could not copy manifest %s to %s. Skipping uninstall of <info>%s</info>.", $manifest, $tmpFile, $package->getName()), true);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return in_array($packageType, array('joomlatools-composer', 'joomlatools-extension', 'joomlatools-installer', 'joomla-installer'));
    }

    /**
     * {@inheritDoc}
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (Util::isReusableComponent($package)) {
            return true; // If Composer didn't error out, we can conclude it is installed
        }

        $application = Bootstrapper::getInstance()->getApplication();

        if ($application === false)
        {
            if ($this->io->isVerbose()) {
                $this->io->write(sprintf("<comment>Warning:</comment> Can not instantiate application to check if %s is installed", $package->getName()), true);
            }

            return false;
        }

        $installPath = $this->getInstallPath($package);
        
        if (file_exists($installPath . '/joomla.installed')) {
            return true; //This package has already been installed
        }

        $manifest    = Util::getPackageManifest($installPath);

        if ($manifest === false) {
            return false;
        }

        $xml = simplexml_load_file($manifest);

        if($xml instanceof \SimpleXMLElement)
        {
            $type    = (string) $xml->attributes()->type;
            $element = Util::getNameFromManifest($installPath);

            if (empty($element)) {
                return false;
            }

            $extension = $application->getExtension($element, $type);

            if (!is_object($extension)) {
                return false;
            }

            return isset($extension->id) && $extension->id > 0;
        }

        return parent::isInstalled($repo);
    }

    protected function addModificationWarningToFiles(PackageInterface $package) 
    {
        $packagePath = $this->getInstallPath($package);

        $finder = new Finder();
        $finder->files()->name('*.php')->in($packagePath);

        foreach ($finder as $file) {
            if ($file->isWritable()) {
                $contents = $file->getContents();
                
                $fileObj = $file->openFile('w');

                $fileObj->fwrite("<?php\r\n");
                $fileObj->fwrite("/**\r\n");
                $fileObj->fwrite(" *\r\n");
                $fileObj->fwrite(" * WARNING!!: THIS FILE IS GENERATED BY COMPOSER.  PLEASE MAKE ALL CHANGES IN THE COMPOSER PACKAGE.\r\n");
                $fileObj->fwrite(" *\r\n");
                $fileObj->fwrite(" */\r\n?>\r\n");
                $fileObj->fwrite($contents);
            }
        }
    }
}
