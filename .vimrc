set noexpandtab
let php_standard_file = findfile('phpcs.xml', '.;')

let g:syntastic_php_checkers = ['phpcs']
let g:syntastic_php_phpcs_args = '--tab-width=0 --standard=' . php_standard_file
set tabstop=4

let g:syntastic_wordpress_checkers = ['phpcs']
" let g:syntastic_wordpress_phpcs_standard = "WordPress-Core"
let g:syntastic_wordpress_phpcs_standard_file = "phpcs.xml"
let home = $HOME
let g:wordpress_vim_wordpress_path = home . '/html/core'

" let phpcbf_args = ''
" if filereadable(standard_file)
let phpcbf_args = '-e -s --standard=' . php_standard_file
" endif
let g:formatdef_phpcbf_php = '"phpcbf ' . phpcbf_args . '"'
let g:formatters_php = ['phpcbf_php']
