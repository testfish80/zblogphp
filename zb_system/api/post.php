<?php

if (!defined('ZBP_PATH')) {
    exit('Access denied');
}

/**
 * Z-Blog with PHP.
 *
 * @author  Z-BlogPHP Team
 * @version 1.0 2020-07-04
 */

/**
 * 获取文章/页面接口.
 *
 * @return array
 */
function api_post_get()
{
    global $zbp;

    $postId = (int) GetVars('id');

    $relation_info = array(
        'Author' => array(
            'other_props' => array('Url', 'Template', 'Avatar', 'StaticName'),
            'remove_props' => array('Guid', 'Password', 'IP')
        ),
    );
    $relation_info['Category'] = array(
        'other_props' => array('Url', 'Symbol', 'Level', 'SymbolName', 'AllCount'),
    );
    $relation_info['Tags'] = array(
        'other_props' => array('Url', 'Template'),
    );

    if ($postId > 0) {
        $post = new Post();
        // 判断 id 是否有效
        if ($post->LoadInfoByID($postId)) {
            //if ($post->Type != ZC_POST_TYPE_PAGE) {
            //}
            if ($post->Status != ZC_POST_STATUS_PUBLIC && $post->AuthorID != $zbp->user->ID) {
                // 不是本人的非公开页面（草稿或审核状态）
                ApiCheckAuth(true, $post->Type_Actions['all']);
            }
            if ($post->Status == ZC_POST_STATUS_PUBLIC) {
                // 默认为公开状态的文章/页面
                ApiCheckAuth(false, $post->Type_Actions['view']);
            }
            $array = ApiGetObjectArray(
                $post,
                array('Url','TagsCount','TagsName','CommentPostKey','ValidCodeUrl'),
                array(),
                ApiGetAndFilterRelationQuery($relation_info)
            );

            return array(
                'data' => array(
                    'post' => $array,
                ),
            );
        }
    }

    return array(
        'code' => 404,
        'message' => $GLOBALS['lang']['error']['97'],
    );
}

/**
 * 新增/修改 文章/页面接口.
 *
 * @return array
 */
function api_post_post()
{
    global $zbp;

    $postType = (int) GetVars('Type', 'POST');
    $actions = $zbp->GetPostType_Actions($postType);

    ApiCheckAuth(true, $actions['post']);

    try {
        if ($postType == ZC_POST_TYPE_ARTICLE) {
            // 默认为新增/修改文章
            $post = PostArticle();
        } elseif ($postType == ZC_POST_TYPE_PAGE) {
            // 新增/修改页面
            $post = PostPage();
        } else {
            // 新增/修改其它Post类型
            $post = PostPost();
        }
        $zbp->BuildModule();
        $zbp->SaveCache();

        $array = ApiGetObjectArray(
            $post,
            array('Url','TagsCount','TagsName','CommentPostKey','ValidCodeUrl'),
            array(),
            ApiGetAndFilterRelationQuery(array(
                'Category' => array(
                    'other_props' => array('Url', 'Symbol', 'Level', 'SymbolName', 'AllCount'),
                ),
                'Author' => array(
                    'other_props' => array('Url', 'Template', 'Avatar', 'StaticName'),
                    'remove_props' => array('Guid', 'Password', 'IP')
                ),
                'Tags' => array(
                    'other_props' => array('Url', 'Template'),
                ),
            ))
        );

        return array(
            'message' => $GLOBALS['lang']['msg']['operation_succeed'],
            'data' => array(
                'post' => $array,
            ),
        );
    } catch (Exception $e) {
        return array(
            'code' => 500,
            'message' => $GLOBALS['lang']['msg']['operation_failed'] . ' ' . $e->getMessage(),
        );
    }

    return array(
        'message' => $GLOBALS['lang']['msg']['operation_succeed'],
    );
}

/**
 * 删除文章/页面接口.
 *
 * @return array
 */
function api_post_delete()
{
    global $zbp;

    ApiVerifyCSRF();

    $post = $zbp->GetPostByID((int) GetVars('id'));
    if (empty($post->ID)) {
        return array(
            'code' => 404,
            'message' => $GLOBALS['lang']['error']['97'],
        );
    }
    $type = $post->Type;

    // 默认为删除文章
    ApiCheckAuth(true, $post->Type_Actions['del']);
    try {
        if ($type == ZC_POST_TYPE_ARTICLE) {
            // 默认为删除文章
            DelArticle();
        } elseif ($type == ZC_POST_TYPE_PAGE) {
            // 删除页面
            DelPage();
        } else {
            // 删除其它Post类型
            DelPost();
        }
        $zbp->BuildModule();
        $zbp->SaveCache();
    } catch (Exception $e) {
        return array(
            'code' => 500,
            'message' => $GLOBALS['lang']['msg']['operation_failed'] . ' ' . $e->getMessage(),
        );
    }

    return array(
        'message' => $GLOBALS['lang']['msg']['operation_succeed'],
    );
}

/**
 * 列出文章/页面接口.
 *
 * @return array
 */
function api_post_list()
{
    global $zbp;

    $cateId = (int) GetVars('cate_id');
    $tagId = (int) GetVars('tag_id');
    $authId = (int) GetVars('auth_id');
    $date = GetVars('date');
    $mng = strtolower((string) GetVars('manage')); //&manage=1
    $type = (int) GetVars('type');
    $actions = $zbp->GetPostType_Actions($type);

    // 组织查询条件
    $where = array();
    if ($cateId > 0) {
        $where[] = array('=', 'log_CateID', $cateId);
    }
    if ($tagId > 0) {
        $where[] = array('LIKE', 'log_Tag', '%{' . $tagId . '}%');
    }
    if (!empty($authId)) {
        $where[] = array('=', 'log_AuthorID', $authId);
    }
    if (!empty($date)) {
        $time = strtotime(GetVars('date', 'GET'));
        if (strrpos($date, '-') !== strpos($date, '-')) {
            $where[] = array('BETWEEN', 'log_PostTime', $time, strtotime('+1 day', $time));
        } else {
            $where[] = array('BETWEEN', 'log_PostTime', $time, strtotime('+1 month', $time));
        }
    }
//Logs_Dump($zbp->user->ID);
    $where[] = array('=', 'log_Type', $type);
    // 权限验证
    if (!empty($mng)) {
        //检查管理模式权限
        ApiCheckAuth(true, $actions['manage']);
        // 如果没有管理all权限
        if (!$zbp->CheckRights($actions['all'])) {
            $where[] = array('=', 'log_AuthorID', $zbp->user->ID);
        }
        $limitCount = $zbp->option['ZC_MANAGE_COUNT'];
    } else {
        // 默认非管理模式
        ApiCheckAuth(false, $actions['view']);
        $limitCount = $zbp->option['ZC_DISPLAY_COUNT'];
    }

    $filter = ApiGetRequestFilter(
        $limitCount,
        array(
            'ID' => 'log_ID',
            'CreateTime' => 'log_CreateTime',
            'PostTime' => 'log_PostTime',
            'UpdateTime' => 'log_UpdateTime',
            'CommNums' => 'log_CommNums',
            'ViewNums' => 'log_ViewNums'
        )
    );
    $order = $filter['order'];
    $limit = $filter['limit'];
    $option = $filter['option'];

    $listArr = ApiGetObjectArrayList(
        $zbp->GetPostList('*', $where, $order, $limit, $option),
        array('Url','TagsCount','TagsName','CommentPostKey','ValidCodeUrl'),
        array(),
        ApiGetAndFilterRelationQuery(array(
            'Category' => array(
                'other_props' => array('Url', 'Symbol', 'Level', 'SymbolName', 'AllCount'),
            ),
            'Author' => array(
                'other_props' => array('Url', 'Template', 'Avatar', 'StaticName'),
                'remove_props' => array('Guid', 'Password', 'IP')
            ),
            'Tags' => array(
                'other_props' => array('Url', 'Template'),
            ),
        ))
    );
    $paginationArr = ApiGetPaginationInfo($option);

    return array(
        'data' => array(
            'list' => $listArr,
            'pagination' => $paginationArr,
        ),
    );
}
