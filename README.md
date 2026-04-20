IIIF from File (module for Omeka S)
====================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[IIIF from File] is a module for [Omeka S] that exports local media image files
to a remote IIIF-compatible repository and replaces the local files with IIIF
image references.

Currently supported repositories:
- [Nakala] (production and test environments)

Planned:
- [Dataverse] (CNRS)

The export process:
1. Upload each image file to the remote repository
2. Create a data object with mapped metadata
3. Retrieve the IIIF image URL
4. Replace (or create) the local media with an IIIF image reference
5. Optionally store the remote identifier (DOI) and IIIF URL as media properties

The invert process can be done with the module [IIIF to File].


Installation
------------

See general end user documentation for [installing a module].

The module requires [Common], that should be installed first.

It is recommended to use the module [Vips] or at list to install [libvips], but
this is not a requirement, only a recommendation to avoid memory issues.
ImageMagick works fine too.

### From the zip

Download the last release [IiifFromFile.zip] from the list of releases, and
uncompress it in the `modules` directory.

### From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `IiifFromFile`, go to the root of the module, and run:

```sh
composer install --no-dev
```

Then install it like any other Omeka module and follow the config instructions.

### For test

The module includes a comprehensive test suite with unit and functional tests.
Run them from the root of Omeka:

```sh
vendor/bin/phpunit -c modules/IiifFromFile/phpunit.xml --testdox
```


Usage
-----

1. Go to Admin > IIIF from File
2. Select the destination repository
3. Configure collection parameters
4. Define the item query to select items
5. Map metadata properties
6. Enter API credentials
7. Click "Export"

A background job processes the items and exports each image media.

### Metadata mapping

The mapping format is one line per property:

```
remote_property = local_source
```

The local source can be:
- A media property: `dcterms:title`
- An item property: `o:item/dcterms:title`
- A fixed value: `"Institution Name"`

Example:
```
dcterms:title = o:item/dcterms:title
dcterms:creator = "My Institution"
dcterms:type = o:item/dcterms:type
```


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.

This module permanently modifies media records by replacing local files with
IIIF references. Make sure to backup your database and files before running
the export.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

- Copyright Daniel Berthereau, 2026 (see [Daniel-KM] on GitLab)

The module was built for Institut de l’Information Scientifique et Technique ([INIST]).


[IIIF from File]: https://gitlab.com/Daniel-KM/Omeka-S-module-IiifFromFile
[Omeka S]: https://omeka.org/s
[Nakala]: https://nakala.fr
[Dataverse]: https://dataverse.org
[IIIF to File]: https://gitlab.com/Daniel-KM/Omeka-S-module-IiifToFile
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[IiifFromFile.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-IiifFromFile/-/releases
[installing a module]: https://omeka.org/s/docs/user-manual/modules/
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-IiifFromFile/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[INIST]: https://www.inist.fr
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
