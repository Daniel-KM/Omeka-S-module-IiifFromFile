IIIF from File (module for Omeka S)
====================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[IIIF from File] is a module for [Omeka S] that exports local media image files
to a remote IIIF-compatible repository and replaces the local files with IIIF
image references. When the remote repository does not support IIIF, the media is
still managed as a simple file via the remote url.

Currently supported repositories:
- [Nakala] (production and test environments)
- [Dataverse] ([demo], [Recherche Data Gouv] (CNRS), [Data IndoRES])

The export process:
1. Upload each image file to the remote repository
2. Create a data object with mapped metadata
3. Retrieve the IIIF image URL
4. Replace (or create) the local media with an IIIF image reference
5. Optionally store the remote identifier (DOI) and IIIF URL as media properties

Once items have been exported, the same form provides a sync action to push
updated Omeka metadata back to the remote record.

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

The module includes a unit, functional and integration test suite. Run it from
the root of Omeka:

```sh
vendor/bin/phpunit -c modules/IiifFromFile/phpunit.xml --testdox
```

#### Integration tests (network)

Integration tests hit live test endpoints. They are skipped automatically when
the endpoint is unreachable or when no API token is configured.

##### Nakala (apitest.nakala.fr)

By default the suite uses the public test token "Unesco" published by Huma-Num
at https://documentation.huma-num.fr/nakala-preprod/. Override it with your own
token via the `NAKALA_TEST_API_KEY` environment variable.

##### Dataverse (demo.dataverse.org)

`demo.dataverse.org` does not provide a public token. Create a free account at
https://demo.dataverse.org, copy the token from `Account → API Token`, then
export it before running the tests:

```sh
export DATAVERSE_DEMO_API_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
vendor/bin/phpunit -c modules/IiifFromFile/phpunit.xml --testdox
```

The token is bound to your demo account and can be regenerated from the same
page if leaked.

Read-only tests (connection, fetch public dataset) run with the token alone.
The dataset-creation/upload test additionally requires the alias of the parent
dataverse where the dataset will be created, exported as `DATAVERSE_DEMO_PARENT`.
The account must have the `Dataset Creator` (or `Curator`/`Admin`) role on that
parent. On `demo.dataverse.org`, the root collection may refuse `AddDataset` to
self-registered users depending on the current policy; if so, create your own
sub-dataverse via the web UI (home page → **Add Data → New Dataverse**) and use
its alias instead:

```sh
export DATAVERSE_DEMO_API_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
export DATAVERSE_DEMO_PARENT=subdataversetest
vendor/bin/phpunit -c modules/IiifFromFile/phpunit.xml --testdox
```

The test creates a small probe dataset, adds a temporary file, then deletes the
draft to leave the parent dataverse clean.


Usage
-----

1. Go to Admin > IIIF from File
2. Select the destination repository
3. Configure collection / status / language / extra deposit parameters
4. Define the item query to select items
5. Map metadata properties
6. Enter API credentials
7. Pick the action: Export (new files) or Sync (already exported records)
8. For Export: pick the ingester (`auto`, `iiif`, `url`, `url with local
   storage`) and the post-deposit media handling (`add`, `convert and delete
   original`, `convert and delete derivatives`).
9. For Sync: pick the sync mode (`complete`, `overwrite`, `replace`) and
   optionally a status to push to the remote.
10. Click "Run"

A background job processes the items and applies the selected action.

### Ingester choices (Export only)

| Choice                       | Resulting Omeka media                                              |
|------------------------------|--------------------------------------------------------------------|
| `auto`                       | IIIF when the connector exposes a IIIF Image API, else URL         |
| `iiif`                       | Force IIIF ingester (info.json source)                             |
| `url` (without local copy)   | URL ingester pointing to the remote file (no local file kept)      |
| `url` (with local storage)   | URL ingester, file copied locally as a backup                      |

### Language handling

Textual metadata pushed to Nakala carry a BCP-47 language tag. The tag is read
from each Omeka value when set (`ValueRepresentation::lang()`). The "Default
language code" form field provides the fallback when an Omeka value has no
language. Dataverse citation metadata blocks ignore language tags.

### Metadata mapping

The mapping format is one line per property and is formatted as "destination = source":

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

#### Required fields by repository

##### Nakala

Nakala enforces five mandatory metas (filled with defaults when absent from the
mapping):

| Property URI                          | Mapping key      | Example                                       |
|---------------------------------------|------------------|-----------------------------------------------|
| `http://nakala.fr/terms#title`        | `nakala:title`   | `o:item/dcterms:title`                        |
| `http://nakala.fr/terms#creator`      | `nakala:creator` | `o:item/dcterms:creator`                      |
| `http://nakala.fr/terms#type`         | `nakala:type`    | `"http://purl.org/coar/resource_type/c_c513"` |
| `http://nakala.fr/terms#created`      | `nakala:created` | `o:item/dcterms:date`                         |
| `http://nakala.fr/terms#license`      | `nakala:license` | `"CC-BY-4.0"`                                 |

Minimal mapping example for Nakala:

```
nakala:title = o:item/dcterms:title
nakala:creator = o:item/dcterms:creator
nakala:type = "http://purl.org/coar/resource_type/c_c513"
nakala:created = o:item/dcterms:date
nakala:license = "CC-BY-4.0"
```

##### Dataverse

The citation block of a Dataverse dataset requires five fields. The connector
fills them with sensible defaults when absent. Dataset creation also requires a
parent dataverse alias (set in the "Collection" parameter of the export form)
where the user has the `Dataset Creator` (or `Curator`/`Admin`) role.

| Field             | Mapping key                     | Notes                            |
|-------------------|---------------------------------|----------------------------------|
| `title`           | `title`                         | Dataset title                    |
| `author`          | `author`                        | `Surname, Givenname`             |
| `datasetContact`  | `contact_name`, `contact_email` | Email is mandatory               |
| `dsDescription`   | `description`                   | Free text                        |
| `subject`         | `subject`                       | Controlled vocabulary, see below |

`subject` must be one of: `Agricultural Sciences`, `Arts and Humanities`,
`Astronomy and Astrophysics`, `Business and Management`, `Chemistry`, `Computer and Information Science`,
`Earth and Environmental Sciences`, `Engineering`, `Law`, `Mathematical Sciences`,
`Medicine, Health and Life Sciences`, `Physics`, `Social Sciences`, `Other`.

Optional mapping keys: `production_date` (or `date`), `keywords`
(semicolon-separated or list), `license` (defaults to `CC BY 4.0`).

Minimal mapping example for Dataverse:

```
title = o:item/dcterms:title
author = o:item/dcterms:creator
contact_name = "Daniel Berthereau"
contact_email = "noreply@example.org"
description = o:item/dcterms:description
subject = "Other"
```

### Synchronization

Three sync modes are available:

- Complete: only add metadata missing on the remote record (do not touch
  existing remote values).
- Overwrite: replace the remote values for properties present in the mapping and
  keep the remote values for unmapped properties.
- Replace: replace the remote record entirely with the mapped metadata (any
  unmapped remote metadata is discarded).


TODO
----

- [ ] Add a mode source = destination
- [ ] Integrate [Mapper] for complex mappings.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.

This module permanently modifies media records by replacing local files with
IIIF or URL references. Make sure to backup your database and files before
running the export.


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
[demo]: https://demo.dataverse.org
[Recherche Data Gouv]: https://entrepot.recherche.data.gouv.fr
[Data IndoRES]: https://data.indores.fr
[Cantaloupe]: https://cantaloupe-project.github.io
[IIIF to File]: https://gitlab.com/Daniel-KM/Omeka-S-module-IiifToFile
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[Mapper]: https://gitlab.com/Daniel-KM/Omeka-S-module-Mapper
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
