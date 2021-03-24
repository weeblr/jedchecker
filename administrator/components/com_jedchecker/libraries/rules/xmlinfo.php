<?php
/**
 * @package    Joomla.JEDChecker
 *
 * @copyright  Copyright (C) 2017 - 2019 Open Source Matters, Inc. All rights reserved.
 * 			   Copyright (C) 2008 - 2016 compojoom.com . All rights reserved.
 * @author     Daniel Dimitrov <daniel@compojoom.com>
 *             eaxs <support@projectfork.net>
 *
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die('Restricted access');


// Include the rule base class
require_once JPATH_COMPONENT_ADMINISTRATOR . '/models/rule.php';


/**
 * class JedcheckerRulesXMLinfo
 *
 * This class searches all xml manifestes for specific tags
 *
 * @since  1.0
 */
class JedcheckerRulesXMLinfo extends JEDcheckerRule
{
	/**
	 * The formal ID of this rule. For example: SE1.
	 *
	 * @var    string
	 */
	protected $id = 'INFO_XML';

	/**
	 * The title or caption of this rule.
	 *
	 * @var    string
	 */
	protected $title = 'COM_JEDCHECKER_INFO_XML';

	/**
	 * The description of this rule.
	 *
	 * @var    string
	 */
	protected $description = 'COM_JEDCHECKER_INFO_XML_DESC';

	/**
	 * Mapping of the plugin title prefix to the plugin group
	 *
	 * @var    string[]
	 */
	protected $pluginsGroupMap = array(
		'button' => 'editors-xtd',
		'editor' => 'editors',
		'smartsearch' => 'finder',
		'twofactorauthentication' => 'twofactorauth'
	);

	/**
	 * Initiates the search and check
	 *
	 * @return    void
	 */
	public function check()
	{
		// Find all XML files of the extension
		$files = JFolder::files($this->basedir, '.xml$', true, true);

		$manifestFound = false;

		// Iterate through all the xml files
		foreach ($files as $file)
		{
			// Try to find the license
			if ($this->find($file))
			{
				$manifestFound = true;
			}
		}

		if (!$manifestFound)
		{
			$this->report->addError('', JText::_('COM_JEDCHECKER_INFO_XML_NO_MANIFEST'));
		}
	}

	/**
	 * Reads a file and searches for the license
	 *
	 * @param   string  $file  - The path to the file
	 *
	 * @return boolean True if the manifest file was found, otherwise False.
	 */
	protected function find($file)
	{
		$xml = JFactory::getXml($file);

		// Failed to parse the xml file.
		// Assume that this is not a extension manifest
		if (!$xml)
		{
			return false;
		}

		// Check if this is an extension manifest
		// 1.5 uses 'install', 1.6+ uses 'extension'
		if ($xml->getName() !== 'extension')
		{
			return false;
		}

		// Get extension type
		$type = (string) $xml['type'];

		// Get extension's element name (simulates work of Joomla's installer)

		// Firstly, check for <element> node
		if (isset($xml->element))
		{
			$extension = (string) $xml->element;
		}
		else
		{
			// Otherwise, use <name> node or plugin/module attribute in the <files> section
			$extension = (string) $xml->name;
			if (isset($xml->files))
			{
				foreach ($xml->files->children() as $child)
				{
					if (isset($child[$type]))
					{
						$extension = (string) $child[$type];
					}
				}
			}
		}

		// Filter extension's element name
		$extension = strtolower(JFilterInput::getInstance()->clean($extension, 'cmd'));

		// Component's element name starts with com_
		if ($type === 'component' && strpos($extension, 'com_') !== 0)
		{
			$extension = 'com_' . $extension;
		}

		// Plugin's element name starts with plg_
		if ($type === 'plugin' && isset($xml['group']) && strpos($extension, 'plg_') !== 0)
		{
			$extension = 'plg_' . $xml['group'] . '_' . $extension;
		}

		// Load the language of the extension (if any)
		$lang = JFactory::getLanguage();

		// search for .sys.ini translation file
		$lang_dir = dirname($file);
		$lang_tag = 'en-GB'; // $lang->getDefault();

		// Populate list of directories to look for
		$lookup_lang_dirs = array();

		if (isset($xml->administration->files['folder']))
		{
			$lookup_lang_dirs[] = trim($xml->administration->files['folder'], '/') . '/language/' . $lang_tag;
		}

		if (isset($xml->files['folder']))
		{
			$lookup_lang_dirs[] = trim($xml->files['folder'], '/') . '/language/' . $lang_tag;
		}

		$lookup_lang_dirs[] = 'language/' . $lang_tag;

		if (isset($xml->administration->languages))
		{
			$folder = trim($xml->administration->languages['folder'], '/');

			foreach ($xml->administration->languages->language as $language)
			{
				if (trim($language['tag']) === $lang_tag)
				{
					$lookup_lang_dirs[] = trim($folder . '/' . dirname($language), '/');
				}
			}
		}

		if (isset($xml->languages))
		{
			$folder = trim($xml->languages['folder'], '/');

			foreach ($xml->languages->language as $language)
			{
				if (trim($language['tag']) === $lang_tag)
				{
					$lookup_lang_dirs[] = trim($folder . '/' . dirname($language), '/');
				}
			}
		}

		$lookup_lang_dirs[] = '';

		$lookup_lang_dirs = array_unique($lookup_lang_dirs);

		// Looking for language file in specified directories
		foreach ($lookup_lang_dirs as $dir)
		{
			$lang_sys_file =
				$lang_dir . '/' .
				($dir === '' ? '' : $dir . '/') .
				$lang_tag. '.' . $extension . '.sys.ini';
			if (is_file($lang_sys_file))
			{
				$loadLanguage = new ReflectionMethod($lang, 'loadLanguage');
				$loadLanguage->setAccessible(true);
				$loadLanguage->invoke($lang, $lang_sys_file, $extension);
				break;
			}
		}

		// Get the real extension's name now that the language has been loaded
		$extension_name = $lang->_((string) $xml->name);

		$info[] = JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_XML', $extension_name);
		$info[] = JText::sprintf('COM_JEDCHECKER_INFO_XML_VERSION_XML', (string) $xml->version);
		$info[] = JText::sprintf('COM_JEDCHECKER_INFO_XML_CREATIONDATE_XML', (string) $xml->creationDate);

		$this->report->addInfo($file, implode('<br />', $info));

		// NM3 - Listing name contains “module” or “plugin”
		if (preg_match('/\b(?:module|plugin)\b/i', $extension_name))
		{
			$this->report->addError($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_MODULE_PLUGIN', $extension_name));
		}

		// The "template" is reserved keyword
		if (stripos($extension_name, 'template') !== false)
		{
			$this->report->addWarning($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_RESERVED_KEYWORDS', $extension_name));
		}

		// NM5 - Version in name/title
		if (preg_match('/(?:\bversion\b|\d\.\d)/i', $extension_name))
		{
			$this->report->addError($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_VERSION', $extension_name));
		}

		if (stripos($extension_name, 'joomla') === 0)
		{
			// An extension name can't start with the word "Joomla"
			$this->report->addError($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_JOOMLA', $extension_name));
		}
		elseif (stripos($extension_name, 'joom') !== false)
		{
			// Extensions that use "Joomla" or a derivative of Joomla in the extension name need to be licensed by OSM
			$this->report->addWarning($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_JOOMLA_DERIVATIVE', $extension_name));
		}

		$url = (string)$xml->authorUrl;
		if (stripos($url, 'joom') !== false)
		{
			$domain = (strpos($url, '//') === false) ? $url : parse_url(trim($url), PHP_URL_HOST);

			if (stripos($domain, 'joom') !== false)
			{
				// Extensions that use "Joomla" or a derivative of Joomla in the domain name need to be licensed by OSM
				$this->report->addError($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_URL_JOOMLA_DERIVATIVE', $url, 'https://tm.joomla.org/approved-domains.html'));
			}
		}

		if ($type === 'component' && isset($xml->administration->menu))
		{
			$menu_name = $lang->_((string) $xml->administration->menu);
			// Do name the Component's admin menu the same as the extension name
			if ($extension_name !== $menu_name)
			{
				$this->report->addWarning($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_ADMIN_MENU', $menu_name, $extension_name));
			}
		}

		if ($type === 'plugin')
		{
			// The name of your plugin must comply with the JED naming conventions - plugins in the form “{Type} - {Extension Name}”.
			$parts = explode(' - ', $extension_name, 2);
			$extension_name_group = isset($parts[1]) ? strtolower(preg_replace('/\s/', '', $parts[0])) : false;
			$group = (string) $xml['group'];

			if ($extension_name_group !== $group && $extension_name_group !== str_replace('-', '', $group)
				&& !(isset($this->pluginsGroupMap[$extension_name_group]) && $this->pluginsGroupMap[$extension_name_group] === $group)
			)
			{
				$this->report->addWarning($file, JText::sprintf('COM_JEDCHECKER_INFO_XML_NAME_PLUGIN_FORMAT', $extension_name));
			}
		}

		// All checks passed. Return true
		return true;
	}
}
